<?php
namespace app\model;

use think\facade\Cookie;

/**
 * 登录会话表：每个浏览器/设备一条记录。
 * 以前登录信息直接写在 users 表的 skey/sid 上，导致「一处登录，别处掉线」，
 * 现在改成每个设备一条，互不影响。
 */
class UserSession extends Base
{
    protected $autoWriteTimestamp = false;

    // 记一条会话（同一个 skey+sid 重复登录就更新）
    public static function add($uid, $skey, $sid, $keep = 0, $gc = true)
    {
        $uid  = intval($uid);
        $skey = (string) $skey;
        $sid  = (string) $sid;
        if ($uid <= 0 || $skey === '' || $sid === '') {
            return false;
        }
        $data = [
            'uid'    => $uid,
            'skey'   => $skey,
            'sid'    => $sid,
            'ip'     => function_exists('real_ip') ? real_ip() : '',
            'time'   => time(),
            'expire' => $keep > 0 ? time() + intval($keep) : 0,
        ];
        $find = new static();
        $row = $find->where('uid', $uid)->where('skey', $skey)->where('sid', $sid)->find();
        if ($row) {
            $upd = new static();
            $upd->where('id', $row['id'])->update($data);
        } else {
            $ins = new static();
            $ins->insert($data);
        }
        if ($gc) {
            self::gc($uid);
        }
        return true;
    }

    // 老 cookie 补一条记录（升级前登录的会话不用重新登录）
    public static function remember($uid, $skey, $sid)
    {
        return self::add($uid, $skey, $sid, 0, false);
    }

    // 校验：记录存在且没过期
    public static function check($uid, $skey, $sid)
    {
        $uid = intval($uid);
        $skey = (string) $skey;
        $sid = (string) $sid;
        if ($uid <= 0 || $skey === '' || $sid === '') {
            return false;
        }
        $self = new static();
        $row = $self->where('uid', $uid)->where('skey', $skey)->where('sid', $sid)->find();
        if (!$row) {
            return false;
        }
        if (!empty($row['expire']) && intval($row['expire']) < time()) {
            $del = new static();
            $del->where('id', $row['id'])->delete();
            return false;
        }
        return $row;
    }

    // 退出登录：只删当前这台设备
    public static function forget($skey, $sid)
    {
        $skey = (string) $skey;
        $sid = (string) $sid;
        if ($skey === '' || $sid === '') {
            return false;
        }
        $self = new static();
        return $self->where('skey', $skey)->where('sid', $sid)->delete();
    }

    public static function forgetCurrent()
    {
        return self::forget(Cookie::get('skey'), Cookie::get('sid'));
    }

    // 改密码/重置密码：把这个用户所有设备都下线
    public static function forgetAll($uid)
    {
        $uid = intval($uid);
        if ($uid <= 0) {
            return false;
        }
        $self = new static();
        return $self->where('uid', $uid)->delete();
    }

    // 清理过期记录；单个用户最多留 20 条
    public static function gc($uid = 0)
    {
        try {
            $exp = new static();
            $exp->where('expire', '>', 0)->where('expire', '<', time())->delete();
            $uid = intval($uid);
            if ($uid > 0) {
                $q = new static();
                $ids = $q->where('uid', $uid)->order('id desc')->limit(20, 100)->column('id');
                if (!empty($ids)) {
                    $d = new static();
                    $d->whereIn('id', $ids)->delete();
                }
            }
        } catch (\Exception $e) {
            return false;
        }
        return true;
    }
}
