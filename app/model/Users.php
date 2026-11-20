<?php
namespace app\model;

use think\facade\Cookie;
use think\facade\Config;
use think\facade\Db;

class Users extends Base
{
    public static function cleanupInactiveUsers()
    {
        $negativetime = Config::get('web.negativetime');
        $ninetyDaysAgo = date('Y-m-d H:i:s', strtotime("-{$negativetime} days"));
        $users = Db::name('users')
            ->where('power', 2)
            ->select();
        $deletedUsers = []; 
        foreach ($users as $user) {
            if ($user['time'] < strtotime($ninetyDaysAgo)) {
                Db::name('users')->where('uid', $user['uid'])->delete();
                $deletedUsers[] = [
                    'uid' => $user['uid'],
                    'username' => $user['username']
                ]; 
            }
        }
        return $deletedUsers;
    }

    public static function search()
    { 
        $page = input('page/d');
        $pageSize = input('limit/d');
        $page = ($page < 1) ? 1 : $page;
        $pageSize = ($pageSize < 1 || $pageSize > 50) ? 10 : $pageSize;
        $self = new static();
        $query = $self->alias('a');  
        $username = input('username');
        !empty($username) && $query->where('a.username', '=', $username);
        $query2 = clone $query;
        return [
            'total' => $query2->count('a.uid'),
            'list' => $query->page($page, $pageSize)->order('a.regtime desc')->select()
        ];
    }

    public static function qqlogin($data)
    {
        $self = new static();
        $token = $data['openid'];
        $userInfo = $self->where('token', '=', $token)->find();
        $str = $userInfo['username'] . $userInfo['password'] . time() . real_ip();
        $sign = md5($str . md5('11144452888'));
        $keep = self::loginKeepSeconds();
        Cookie::set('pskey', authcode($userInfo['uid'], 'ENCODE', AUTH_KEY), $keep);
        Cookie::set('skey', md5($str), $keep);
        Cookie::set('sid', $sign, $keep);
        $update = $self->where('uid', '=', $userInfo['uid'])
            ->update([
                'skey' => md5($str),
                'sid' => $sign,
                'dlip' => real_ip(),
                'city' => get_ip_city(real_ip()),
                'time' => time()
            ]);
        if ($update) {
            UserSession::add($userInfo['uid'], md5($str), $sign, $keep);   // 每个设备一条，互不踢下线
        }
        return $update ? true : false;
    }

    public static function reg($data)
    {
        $self = new static();
        $insert['username'] = $data['username'];
        $insert['password'] = password_hash($data['password'], PASSWORD_BCRYPT); // 使用 password_hash 代替 md5
        $insert['qq'] = $data['qq'];
        $insert['mail'] = $data['mail'];
        $insert['skey'] = '0';
        $insert['regtime'] = date("Y-m-d H:i:s");
        $insert['regip'] = real_ip();
        $insert['time'] = time();
        $insert['token2'] = strtoupper(md5(bin2hex(random_bytes(16))));
        $insert['limit2'] = Config::get('web.reglimit2');
        $insert['power'] = 2;
        $insert['pie'] = Config::get('web.regpie');
        $check = $self->insert($insert);
        return $check ? true : false;
    }

    // 登录状态保持多久（后台「更多设置 → 登录保持」，单位：天；0 = 关掉浏览器就失效）
    private static function loginKeepSeconds()
    {
        $raw = Config::get('web.login_keep_days');
        $days = ($raw === null || $raw === '') ? 30 : intval($raw);
        if ($days < 0) {
            $days = 0;
        }
        if ($days > 3650) {
            $days = 3650;
        }
        return $days * 86400;
    }

    public static function login($userInfo)
    {
        $self = new static();
        $str = $userInfo['username'] . $userInfo['password'] . time() . real_ip();
        $sign = md5($str . md5('11144452888'));
        
        $userInfoFromDb = $self->where('username', '=', $userInfo['username'])->find();
        if ($userInfoFromDb && password_verify($userInfo['password'], $userInfoFromDb['password'])) { // 使用 password_verify 验证密码
            $keep = self::loginKeepSeconds();
            Cookie::set('pskey', authcode($userInfoFromDb['uid'], 'ENCODE', AUTH_KEY), $keep);
            Cookie::set('skey', md5($str), $keep);
            Cookie::set('sid', $sign, $keep);
            $update = $self->where('uid', '=', $userInfoFromDb['uid'])
                ->update([
                    'skey' => md5($str),
                    'sid' => $sign,
                    'dlip' => real_ip(),
                    'city' => get_ip_city(real_ip()),
                    'time' => time()
                ]);
            if ($update) {
                UserSession::add($userInfoFromDb['uid'], md5($str), $sign, $keep);   // 每个设备一条，互不踢下线
            }
            return $update ? true : false;
        }
        return false;
    }

    public static function checkUsername($username)
    {
        $self = new static();
        return !$self->where("username", $username)->find();
    }

    public static function checkQqToken($token)
    {
        $self = new static();
        return (bool) $self->where("token", $token)->find();
    }

    public static function checkQq($qq)
    {
        $self = new static();
        return !$self->where("qq", $qq)->find();
    }

    public static function checkPass($userInfo)
    {
        $self = new static();
        $row = $self->where('username', $userInfo['username'])->find();
        return $row && password_verify($userInfo['password'], $row['password']);
    }

    public static function updateQQ($uid, $newQQ)
    {
        $user = self::where('uid', '=', $uid)->field('qq')->find();
        if (!$user) {
            return ['code' => -1, 'message' => '用户不存在', 'msg' => '用户不存在'];
        }
        if ($user['qq'] === $newQQ) {
            return ['code' => 1, 'message' => '新QQ号不能与旧QQ号相同', 'msg' => '新QQ号不能与旧QQ号相同'];
        }
        $update = self::where('uid', '=', $uid)->update(['qq' => $newQQ]);
        return $update !== false ? ['code' => 0, 'message' => 'QQ号修改成功', 'msg' => 'QQ号修改成功'] : ['code' => 2, 'message' => 'QQ号修改失败', 'msg' => 'QQ号修改失败'];
    }

    public static function change_Password($uid, $data)
    {
        $self = new static();
        $user_row = $self->where('uid', '=', $uid)->field('password')->find();
        
        $rule = [
            'password'  => 'require|alphaNum|length:5,20',
        ];
        $message = [
            'password.require' => '密码不能为空',
            'password.alphaNum' => '密码只能字母和数字',
            'password.length' => '密码长度必须在5到20之间',
        ];

        $validate = new \think\Validate($rule, $message);
        $result = ['code' => -1, 'msg' => '操作失败'];

        if (!$validate->check($data)) {
            $result = ['code' => -1, 'msg' => $validate->getError()];
        } elseif (password_verify($data['outpass'], $user_row['password'])) {
            $new_pass = password_hash($data['password'], PASSWORD_BCRYPT); // 使用 password_hash 加密新密码
            if ($self->where('uid', '=', $uid)->update(['password' => $new_pass])) {
                UserSession::forgetAll($uid);
                Cookie::delete('skey');
                Cookie::delete('sid');
                Cookie::delete('pskey');
                Cookie::delete('user_token');
                $result = ['code' => 0, 'message' => '修改密码成功，请重新登录', 'msg' => '修改密码成功，请重新登录'];
            } else {
                $result = ['code' => 1, 'message' => '密码更新失败，请重试', 'msg' => '密码更新失败，请重试'];
            }
        } else {
            $result = ['code' => 1, 'message' => '原密码不正确！', 'msg' => '原密码不正确！'];
        }

        return $result;
    }

    public static function findpassword($data)
    {
        $self = new static();
        $user_row = $self->where('mail', '=', $data['mail'])->field('password,uid')->find();
        if (!$user_row) {
            return ['code' => -1, 'msg' => '该邮箱未注册'];
        }

        $new_pass = password_hash($data['password'], PASSWORD_BCRYPT); // 使用 password_hash 加密新密码
        $rule = [
            'password'  => 'require|alphaNum|length:5,20',
        ];
        $message = [
            'password.require' => '密码不能为空',
            'password.alphaNum' => '密码只能字母和数字',
            'password.length' => '密码长度必须在5到20之间',
        ];

        $validate = new \think\Validate($rule, $message);
        if (!$validate->check($data)) {
            return ['code' => -1, 'msg' => $validate->getError()];
        } elseif ($self->where('mail', '=', $data['mail'])->update(['password' => $new_pass])) {
            UserSession::forgetAll(isset($user_row['uid']) ? $user_row['uid'] : 0);
            return ['code' => 0, 'msg' => '密码重置成功，请重新登录'];
        } else {
            return ['code' => -1, 'msg' => '密码重置失败，请重试'];
        }
    }
    public static function updateByUid($uid, $data)
    {
        $self = new static();
        if ($result = $self->where('uid', '=', $uid)->update($data)) {
            return $result;
        } else {
            return false;
        }
    }
    
    public static function updateBytoken($token, $data)
    {
        $self = new static();
        if ($result = $self->where('token', '=', $token)->update($data)) {
            return $result;
        } else {
            return false;
        }
    }
    
    public static function updateByUsername($username, $data)
    {
        $self = new static();
        if ($result = $self->where('username', '=', $username)->update($data)) {
            return $result;
        } else {
            return false;
        }
    }

    public static function delByUid($uid)
    {
        $self = new static();
        if ($result = $self->where('uid', '=', $uid)->delete()) {
            return $result;
        } else {
            return false;
        }
    }

  /*  public
    static function getLoginUser()
    {
        $self = new static();
		$skey = cookie("skey");
        $pskey = authcode(cookie('pskey'), 'DECODE', AUTH_KEY);
        //获取登录Cookie
        $uid = $pskey;
        //组成查询条件
        $where['uid'] = $uid;
        //通过Cookie存储的数据查询数据库记录
        if (!$uid || !$userInfo = $self->where($where)->find()) {
            //不存在
            return false;
        } else {
            //存在则返回该用户的这条记录内容
            return $userInfo;
        }
    }*/
    public static function getLoginUser()
    {
        $skey = Cookie::get('skey');
        $sid = Cookie::get('sid');
        $pskeyCookie = Cookie::get('pskey');
        if (!$pskeyCookie) {
            return false;
        }
        $uid = authcode($pskeyCookie, 'DECODE', AUTH_KEY);
        if (!$uid) {
            return false;
        }
        $self = new static();
        $userInfo = $self->where('uid', $uid)->find();
        if (!$userInfo) {
            return false;
        }
        // 有会话 cookie 时按会话表校验（多设备各自有效）
        if ($skey && $sid) {
            if (UserSession::check($uid, $skey, $sid)) {
                return $userInfo;
            }
            if (hash_equals((string)$userInfo['sid'], (string)$sid) && (string)$userInfo['skey'] === $skey) {
                UserSession::remember($uid, $skey, $sid);   // 升级前的老登录态，补一条记录继续用
                return $userInfo;
            }
            return false;
        }
        return $userInfo;
    }

    public static function checkLogin()
    {
        $skey = Cookie::get('skey');
        $sid = Cookie::get('sid');
        $pskeyCookie = Cookie::get('pskey');
        if (!$skey || !$pskeyCookie) {
            return [
                'url' => '/console#/user/login',
                'msg' => '请登录后再进行操作！'
            ];
        }
        $uid = authcode($pskeyCookie, 'DECODE', AUTH_KEY);
        if (!$uid) {
            return [
                'url' => '/console#/user/login',
                'msg' => '请登录后再进行操作！'
            ];
        }
        $self = new static();
        $user = $self->where('uid', '=', $uid)->find();
        if (!$user) {
            return [
                'url' => '/console#/user/login',
                'msg' => '请登录后再进行操作！'
            ];
        }
        // 1) 设备会话表里能查到就是有效登录（你在这台登录，不影响另一台）
        if ($sid && UserSession::check($uid, $skey, $sid)) {
            return null;
        }
        // 2) 老 cookie（本次升级前登录的）走用户表兜底，并补一条会话记录，不用重新登录
        if ($sid && hash_equals((string)$user['sid'], (string)$sid) && (string)$user['skey'] === $skey) {
            UserSession::remember($uid, $skey, $sid);
            return null;
        }
        // 3) 确实不是有效登录态，清 cookie
        Cookie::delete('skey');
        Cookie::delete('sid');
        Cookie::delete('pskey');
        return [
            'url' => '/console#/user/login',
            'msg' => '登录状态已失效，请重新登录！'
        ];
    }
}
