<?php
namespace app\controller;

use app\BaseController;
use think\facade\Db;
use think\facade\Config;
use think\facade\View;
use think\facade\Session;
use app\util\geetest\GeetestLib;
use app\model\Users;
use app\model\Player;
use app\model\SongSheet;
use app\model\Plays;
use app\model\PlayerAuth;

class Common extends BaseController
{
    function __construct()
    {
        $config = Db::name('configs')->column('v', 'k');
		$config['version']='V.2024.11.02';
		Config::set($config, 'web');
		// 授权QQ / skey 现在在后台「更多设置 → 音乐授权」里填，这里读配置；
		// 配置为空时退回原来的默认值，保证老站点行为不变。
		$authQq = isset($config['auth_qq']) && trim((string) $config['auth_qq']) !== ''
			? trim((string) $config['auth_qq']) : '2963246343';
		$authSkey = isset($config['auth_skey']) ? trim((string) $config['auth_skey']) : '';
		$data=[
			'music'	=>	music_api, // 勿动，动了播放不了
			'qq'	=>	$authQq,   // 你的授权QQ（后台可改）
			'skey'	=>	$authSkey, // 你的 skey（后台可改）
		];
		Config::set($data, 'api');

		// 后台页面（SPA 片段）不做浏览器缓存：否则改了模板，页面还是旧的
		$pathinfo = strtolower(ltrim(request()->pathinfo(), '/'));
		if ($pathinfo === 'admin' || strpos($pathinfo, 'admin/') === 0) {
			header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
			header('Pragma: no-cache');
			header('Expires: 0');
		}
    }
	
	protected function getSide(){
		$userInfo = Users::getLoginUser();
        // 获取用户拥有的播放器
        $userPlayers = Player::where('user_id',$userInfo['uid'])->select();

        // 获取用户所拥有的歌单
        $userSongSheets = SongSheet::where('user_id',$userInfo['uid'])->select();

        // 设置到session域中
        View::assign('userPlayers',$userPlayers);
        View::assign('userSongSheets',$userSongSheets);
    }
	
	protected function geetest_check($geetest_challenge, $geetest_validate, $geetest_seccode)
    {
        $GtSdk = new GeetestLib('fd772a9a5dbd10d2b164870729abbd2d','f4c61df26a8552cc51697bd53d352b4b');
        $data = [
            "user_id" => time(), # 网站用户id
            "client_type" => "web", #web:电脑上的浏览器；h5:手机上的浏览器，包括移动应用内完全内置的web_view；native：通过原生SDK植入APP应用的方式
            "ip_address" => real_ip() # 请在此处传输用户请求验证时所携带的IP
        ];
        if (session('gtserver') == 1) {   //服务器正常
            if ($GtSdk->success_validate($geetest_challenge, $geetest_validate, $geetest_seccode, $data)) {
                return true;
            } else {
                return false;
            }
        } else {  //服务器宕机,走failback模式
            if ($GtSdk->fail_validate($geetest_challenge, $geetest_validate, $geetest_seccode)) {
                return true;
            } else {
                return false;
            }
        }
    }
	
    public function geetest_captcha()
    {
        $GtSdk = new GeetestLib('fd772a9a5dbd10d2b164870729abbd2d','f4c61df26a8552cc51697bd53d352b4b');
        $data = [
            "user_id" => time(),
            "client_type" => "web",
            "ip_address" => real_ip()
        ];
        $status = $GtSdk->pre_process($data, 1);
        session('gtserver', $status);
        session('user_id', $data['user_id']);
        echo $GtSdk->get_response_str();
    }
}