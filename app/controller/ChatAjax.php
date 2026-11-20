<?php
namespace app\controller;

use app\model\Users;
use app\model\Chat;

/**
 * 留言板（前台）接口
 *
 *   取留言：GET  /ChatAjax/lists?id=0&limit=30     id 传上次看到的最后一条，就只返回比它新的
 *   发留言：POST /ChatAjax/send                   nickname / qq / content（登录用户自动带名字）
 *
 * 说明：gui_chat 表和 app/model/Chat.php 是原始包里就有的，但一直没有控制器和页面，
 *       留言功能等于只写了一半。这里把前台接口补全（带字数校验、限速、防重复）。
 */
class ChatAjax extends Common
{
    public function lists()
    {
        $limit = intval(input('get.limit', 30));
        if ($limit < 1 || $limit > 100) {
            $limit = 30;
        }
        $lastId = intval(input('get.id', 0));

        $query = Chat::order('id', 'desc')->limit($limit);
        if ($lastId > 0) {
            $query = $query->where('id', '>', $lastId);
        }
        $rows = $query->select();
        $rows = (is_object($rows) && method_exists($rows, 'toArray')) ? $rows->toArray() : (array) $rows;
        $rows = array_reverse($rows); // 取最新 N 条后再翻回时间正序

        // 站长发言加个标记
        $adminUids = [];
        foreach ((array) Users::where('power', 0)->column('uid') as $uid) {
            $adminUids[] = intval($uid);
        }

        $data = [];
        foreach ($rows as $row) {
            $time = (string) ($row['sendtime'] ?: $row['time']);
            $data[] = [
                'id' => intval($row['id']),
                'nickname' => (string) $row['nickname'],
                'content' => (string) $row['content'],
                'time' => $time,
                'time_tran' => time_tran($time),
                'is_admin' => in_array(intval($row['uid']), $adminUids, true) ? 1 : 0,
            ];
        }

        $userInfo = Users::getLoginUser();
        $login = new \stdClass();
        if ($userInfo) {
            $login = [
                'uid' => intval($userInfo['uid']),
                'nickname' => (string) $userInfo['username'],
                'qq' => (string) $userInfo['qq'],
            ];
        }

        return json([
            'code' => 0,
            'msg' => '',
            'data' => $data,
            'total' => intval(Chat::count()),
            'enable' => config('web.chat_enable') === '0' ? 0 : 1,
            'login' => $login,
        ]);
    }

    public function send()
    {
        if (config('web.chat_enable') === '0') {
            return json(['code' => -1, 'msg' => '留言板已关闭']);
        }

        $content = trim((string) strip_tags((string) input('post.content', '')));
        $content = (string) preg_replace('/\s+/u', ' ', $content);
        $nickname = trim((string) strip_tags((string) input('post.nickname', '')));
        $qq = trim((string) input('post.qq', ''));

        // 登录用户：名字、QQ 用账号里的，避免冒名
        $userInfo = Users::getLoginUser();
        $uid = 0;
        if ($userInfo) {
            $uid = intval($userInfo['uid']);
            $nickname = (string) $userInfo['username'];
            // 登录用户的 QQ 一律以账号里的为准（忽略前端传来的值，避免冒名或格式报错）
            $qq = preg_match('/^[0-9]{4,15}$/', (string) $userInfo['qq']) ? (string) $userInfo['qq'] : '';
        }

        $len = mb_strlen($content, 'utf-8');
        if ($len < 1) {
            return json(['code' => -1, 'msg' => '留言内容不能为空']);
        }
        if ($len > 200) {
            return json(['code' => -1, 'msg' => '留言最多 200 个字']);
        }
        if ($nickname === '') {
            return json(['code' => -1, 'msg' => '请先填写昵称']);
        }
        if (mb_strlen($nickname, 'utf-8') > 20) {
            return json(['code' => -1, 'msg' => '昵称最多 20 个字']);
        }
        if ($qq !== '' && !preg_match('/^[0-9]{4,15}$/', $qq)) {
            return json(['code' => -1, 'msg' => 'QQ 号格式不对（4~15 位数字）']);
        }

        // 限速：同 IP 60 秒只能发 1 条，1 小时最多 20 条
        $ip = real_ip();
        $last = Chat::where('sendip', $ip)->order('id', 'desc')->find();
        if ($last) {
            $lastTime = strtotime((string) ($last['sendtime'] ?: $last['time']));
            $wait = 60 - (time() - $lastTime);
            if ($wait > 0) {
                return json(['code' => -1, 'msg' => '发言太快啦，' . $wait . ' 秒后再试']);
            }
        }
        $hour = intval(Chat::where('sendip', $ip)->where('sendtime', '>', date('Y-m-d H:i:s', time() - 3600))->count());
        if ($hour >= 20) {
            return json(['code' => -1, 'msg' => '一小时内最多留言 20 条，歇一会儿吧']);
        }
        // 全站突发保护：有人伪造 X-Forwarded-For 绕开单 IP 限速时兜底
        $burst = intval(Chat::where('sendtime', '>', date('Y-m-d H:i:s', time() - 10))->count());
        if ($burst >= 5) {
            return json(['code' => -1, 'msg' => '留言太频繁了，请稍后再试']);
        }
        // 10 分钟内不重复同一句话
        $same = intval(Chat::where('content', $content)->where('sendtime', '>', date('Y-m-d H:i:s', time() - 600))->count());
        if ($same >= 1) {
            return json(['code' => -1, 'msg' => '这句话刚刚已经留言过啦']);
        }

        $now = date('Y-m-d H:i:s');
        $id = Chat::insertGetId([
            'uid' => $uid,
            'qq' => $qq,
            'nickname' => $nickname,
            'content' => $content,
            'time' => $now,
            'sendtime' => $now,
            'sendip' => $ip,
        ]);
        if (!$id) {
            return json(['code' => -1, 'msg' => '留言失败，请稍后再试']);
        }

        $isAdmin = 0;
        if ($uid > 0) {
            $u = Users::where('uid', $uid)->find();
            $isAdmin = ($u && intval($u['power']) === 0) ? 1 : 0;
        }

        return json([
            'code' => 0,
            'msg' => '留言成功',
            'data' => [
                'id' => intval($id),
                'nickname' => $nickname,
                'content' => $content,
                'time' => $now,
                'time_tran' => '刚刚',
                'is_admin' => $isAdmin,
            ],
        ]);
    }
}
