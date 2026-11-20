<?php
namespace app\controller;

use Throwable;
use app\BaseController;
use think\facade\View;
use think\facade\Config;
use app\model\Users;
use app\model\Player;
use app\model\SongSheet;
use app\model\Song;

class Index extends Common
{
    public function index()
    {
        $data = [
            'users' =>  Users::where(1)->count(),
            'players'    =>  Player::where(1)->count(),
            'sheets'    =>  SongSheet::where(1)->count(),
            'songs'  =>  Song::where(1)->count(),
        ];
        $users = Users::orderRand()->limit(12)->select();
        View::assign('data', $data);
        View::assign('users', $users);

        // 首页主题由后台「主题设置」决定
        $theme = home_theme_key();
        // 管理员可以用 ?theme=xxx 预览任意主题，正式访客不受影响
        $preview = (string) input('get.theme', '');
        if ($preview !== '' && array_key_exists($preview, home_themes())) {
            $loginUser = Users::getLoginUser();
            if (!empty($loginUser) && (int) ($loginUser['power'] ?? -1) === 0) {
                $theme = $preview;
            }
        }
        View::assign('theme', $theme);
        View::assign('site', $this->siteData());

        if ($theme === 'classic') {
            return $this->withChatWidget(View::fetch());
        }
        try {
            $html = View::fetch('index/themes/' . $theme);
        } catch (Throwable $e) {
            // 主题文件缺失时回退到经典主题，避免首页直接 500
            $html = View::fetch();
        }
        return $this->withChatWidget($html);
    }

    // 首页展示要用的一些数据：主题清单、播放器、公开歌单、登录状态
    // 表为空或查询出错都不影响首页渲染
    private function siteData()
    {
        $out = [
            'themes' => [],
            'theme_total' => count(home_themes()),
            'players' => [],
            'sheets' => [],
            'login' => [],
        ];
        foreach (home_themes() as $key => $item) {
            $out['themes'][] = [
                'key' => $key,
                'name' => $item['name'],
                'desc' => $item['desc'],
                'c1' => $item['c1'],
                'c2' => $item['c2'],
                'c3' => $item['c3'],
            ];
        }
        try {
            $out['players'] = Player::order('create_time', 'desc')->limit(1)->select()->toArray();
        } catch (Throwable $e) {
        }
        try {
            $out['sheets'] = SongSheet::where('status', 1)->order('create_time', 'desc')->limit(6)->select()->toArray();
        } catch (Throwable $e) {
        }
        try {
            $login = Users::getLoginUser();
            if (!empty($login) && !empty($login['username'])) {
                $out['login'] = ['username' => (string) $login['username']];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    // 所有首页主题统一挂上留言板小组件（主题文件不用改）；在「留言管理」页里可以整体关掉
    private function withChatWidget($html)
    {
        if (config('web.chat_enable') === '0') {
            return $html;
        }
        $snippet = '<link rel="stylesheet" href="/res/chat/chat.css">' . "\n"
            . '<script src="/res/chat/chat.js" defer></script>' . "\n";
        $pos = stripos($html, '</body>');
        if ($pos === false) {
            return $html . $snippet;
        }
        return substr($html, 0, $pos) . $snippet . substr($html, $pos);
    }
}
