<?php
namespace app\controller;
 
use app\BaseController;
use think\facade\View; 
use think\facade\Config;
use think\facade\Db;
use think\facade\Cache;
use think\facade\Request;
use think\facade\Session;
use app\model\Users; 
use app\model\Chat;
use app\model\Player;
use app\model\SongSheet;
use app\model\Song;
use app\model\Plays;
use app\model\PlayerAuth;
use app\model\PlayerSongSheet;
use app\model\Links;
use PHPMailer\SendEmail;

class AdminAjax extends Common
{
   public function cleanupUsers()
{
    $this->checkLogin();
    $deletedUsers = Users::cleanupInactiveUsers();
    
    if (!empty($deletedUsers)) {
        return json([
            'status' => 'success', 
            'message' => '清理成功', 'msg' => '清理成功', 
            'deleted_users' => $deletedUsers
        ]);
    } else {
        return json([
            'status' => 'error', 
            'message' => '清理失败或没有需要删除的用户', 'msg' => '清理失败或没有需要删除的用户'
        ]);
    }
}
    
    public function reset(Request $request)
    {
        $this->checkLogin();
        $userInfo = Users::getLoginUser();
        $newToken = $this->generateNewToken();
        $result = Db::name('users')->where('uid', $userInfo['uid'])->update(['token2' => $newToken]); 
        if ($result) {
            return json(['success' => true, 'newToken' => $newToken]);
        } else {
            return json(['success' => false], 500);
        }
    }

    private function generateNewToken()
    {
        $randomString = bin2hex(random_bytes(16)); 
        return strtoupper(md5($randomString)); 
    }
    
	public function encode_netease_data($data)
    {
        $_key = '7246674226682325323F5E6544673A51';
        $data = json_encode($data);
        if (function_exists('openssl_encrypt')) {
            $data = openssl_encrypt($data, 'aes-128-ecb', pack('H*', $_key));
        } else {
            $_pad = 16 - (strlen($data) % 16);
            $data = base64_encode(mcrypt_encrypt(
                MCRYPT_RIJNDAEL_128,
                hex2bin($_key),
                $data . str_repeat(chr($_pad), $_pad),
                MCRYPT_MODE_ECB
            ));
        }
        $data = strtoupper(bin2hex(base64_decode($data)));
        return ['eparams' => $data];
    }
	
	private function getFilePath($fileName, $content)
	{
		$path = dirname(__FILE__) . "\\$fileName";
		if (!file_exists($path)) {
			file_put_contents($path, $content);
		}
		return $path;
	}

	private function getCupUsageVbsPath()
	{
		return $this->getFilePath(
			'cpu_usage.vbs',
			"On Error Resume Next
			Set objProc = GetObject(\"winmgmts:\\\\.\\root\cimv2:win32_processor='cpu0'\")
			WScript.Echo(objProc.LoadPercentage)"
		);
	} 

	private function getMemoryUsageVbsPath()
	{
		return $this->getFilePath(
			'memory_usage.vbs',
			"On Error Resume Next
			Set objWMI = GetObject(\"winmgmts:\\\\.\\root\cimv2\")
			Set colOS = objWMI.InstancesOf(\"Win32_OperatingSystem\")
			For Each objOS in colOS
			Wscript.Echo(\"{\"\"TotalVisibleMemorySize\"\":\" & objOS.TotalVisibleMemorySize & \",\"\"FreePhysicalMemory\"\":\" & objOS.FreePhysicalMemory & \"}\")
			Next"
		);
	}

	public function getCpuUsage()
	{
		$path = $this->getCupUsageVbsPath();
		exec("cscript -nologo $path", $usage);
		return $usage[0];
	}

	public function getMemoryUsage()
	{
		$path = $this->getMemoryUsageVbsPath();
		exec("cscript -nologo $path", $usage);
		$memory = json_decode($usage[0], true);
		$memory['usage'] = Round((($memory['TotalVisibleMemorySize'] - $memory['FreePhysicalMemory']) / $memory['TotalVisibleMemorySize']) * 100);
		return $memory;
	}

    /**
     * 采集 Linux 的内存 / CPU / 负载
     * 兼容说明：普通 Linux 直接读 /proc/stat、/proc/loadavg；容器 / PRoot / 虚拟主机里这些文件
     *           可能被隐藏（Permission denied），所以逐级降级：CPU 先读 /proc/stat，读不到就统计
     *           各进程 CPU 时间增量；负载用 sys_getloadavg()；内存统一读 /proc/meminfo；
     *           全部读不到才显示 N/A。
     */
    public function getLinuxCount()
    {
        $cores = $this->getCpuCores();

        // ---------- 内存：/proc/meminfo ----------
        $mem_total = 0;
        $mem_available = 0;
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo) {
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $mem_total = intval($m[1]);
            }
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $mem_available = intval($m[1]);
            } elseif (preg_match('/MemFree:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $mem_available = intval($m[1]);
            }
        }
        $mem_used = max($mem_total - $mem_available, 0);
        $mem_usage = $mem_total > 0 ? round(100 * $mem_used / $mem_total, 2) : 0;

        // ---------- CPU ----------
        $cpu_usage = $this->calcCpuUsage($cores);

        // ---------- 负载 ----------
        $load_avg = null;
        $load_raw = null;
        if (function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg();
            if (is_array($load) && isset($load[0]) && is_numeric($load[0])) {
                $load_raw = round(floatval($load[0]), 2);
                $load_avg = round(min($load_raw / $cores * 100, 100), 2);
            }
        }

        return [
            'mem' => $mem_usage . '%',
            'memRealUsed' => $this->getFilesize($mem_used * 1024),
            'memTotal' => $this->getFilesize($mem_total * 1024),
            'cpu' => ($cpu_usage === null) ? 'N/A' : $cpu_usage . '%',
            'load_avg' => ($load_avg === null) ? 'N/A' : $load_avg . '%',
            'load_raw' => ($load_raw === null) ? 'N/A' : $load_raw,
            'cores' => $cores,
        ];
    }

    private function getCpuCores()
    {
        static $cores = null;
        if ($cores !== null) {
            return $cores;
        }
        $cores = 0;
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if ($cpuinfo) {
            $cores = substr_count(strtolower($cpuinfo), 'processor');
        }
        if ($cores <= 0) {
            $cores = intval(trim((string) @shell_exec('nproc')));
        }
        if ($cores <= 0) {
            $cores = 1;
        }
        return $cores;
    }

    private function calcCpuUsage($cores)
    {
        // 方式一：/proc/stat 两次采样（标准 Linux）
        $readStat = function () {
            $stat = @file_get_contents('/proc/stat');
            if (!$stat) {
                return null;
            }
            $line = '';
            foreach (explode("\n", $stat) as $l) {
                if (strpos($l, 'cpu') === 0) {
                    $line = $l;
                    break;
                }
            }
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 5) {
                return null;
            }
            $v = [];
            foreach (array_slice($parts, 1) as $one) {
                $v[] = intval($one);
            }
            $idle = (isset($v[3]) ? $v[3] : 0) + (isset($v[4]) ? $v[4] : 0);
            $busy = (isset($v[0]) ? $v[0] : 0) + (isset($v[1]) ? $v[1] : 0) + (isset($v[2]) ? $v[2] : 0)
                  + (isset($v[5]) ? $v[5] : 0) + (isset($v[6]) ? $v[6] : 0) + (isset($v[7]) ? $v[7] : 0);
            return ['busy' => $busy, 'total' => $busy + $idle];
        };
        // 方式二：容器里 /proc/stat 被隐藏时，用所有进程的 utime + stime 增量估算
        $sample = function () {
            $sum = null;
            foreach ((array) glob('/proc/[0-9]*/stat') as $file) {
                $stat = @file_get_contents($file);
                if (!$stat) {
                    continue;
                }
                $pos = strrpos($stat, ')');
                if ($pos === false) {
                    continue;
                }
                $fields = preg_split('/\s+/', trim(substr($stat, $pos + 1)));
                if (count($fields) < 13) {
                    continue;
                }
                $sum += intval($fields[11]) + intval($fields[12]); // utime + stime
            }
            return $sum;
        };
        // 两种采样一起做，只等一次 200ms，省一半时间
        $statA = $readStat();
        $procA = $sample();
        usleep(200000);
        $statB = $readStat();
        $procB = $sample();

        if ($statA !== null && $statB !== null && $statB['total'] > $statA['total']) {
            return max(0, min(100, round(100 * ($statB['busy'] - $statA['busy']) / ($statB['total'] - $statA['total']), 2)));
        }
        if ($procA !== null && $procB !== null && $procB >= $procA && $cores > 0) {
            $usage = round(100 * (($procB - $procA) / 100) / (0.2 * $cores), 2); // 每秒 100 个时钟滴答
            return max(0, min(100, $usage));
        }
        return null;
    }

private function getFilesize($size)
{
    // 将内存大小转换为合适的格式
    if ($size < 1024) {
        return $size . ' B';
    } elseif ($size < 1048576) {
        return round($size / 1024, 2) . ' KiB';
    } elseif ($size < 1073741824) {
        return round($size / 1048576, 2) . ' MiB';
    } else {
        return round($size / 1073741824, 2) . ' GiB';
    }
}
   public function menu()
    {
		$this->checkLogin();
		$userInfo = Users::getLoginUser();
		if($userInfo['power']==0){
			$userInfo['lv']='管理员';
			$home_head=[
				[
					'jump'	=>	'/',
					'title'	=>	'<i class=\"layui-icon layui-icon-console\"></i> 控制面板'
				],[
					'jump'	=>	'/help',
					'title'	=>	'帮助文档'
				],[
					'jump'	=>	'/webset',
					'title'	=>	'网站设置'
					],[
						'jump'	=>	'/moreset',
						'title'	=>	'更多设置'
				],[
					'jump'	=>	'/themeset',
					'title'	=>	'主题设置'
				],[
					'jump'	=>	'/users/act/list',
					'title'	=>	'用户列表'
				],[
					'jump'	=>	'/links/act/list',
					'title'	=>	'广告列表'
				],[
					'jump'	=>	'/orders/act/list',
					'title'	=>	'订单列表'
				],[
					'jump'	=>	'/serverstate',
					'title'	=>	'服务器状态'
				],[
					'jump'	=>	'/chat/act/list',
					'title'	=>	'留言管理'
				]
			];
		}elseif($userInfo['power']==1){
			$userInfo['lv']='付费版';
			$home_head=[
				[
					'jump'	=>	'/',
					'title'	=>	'<i class=\"layui-icon layui-icon-console\"></i> 控制面板'
				],
				[
					'jump'	=>	'/help',
					'title'	=>	'帮助文档'
				]
			];
		}elseif($userInfo['power']==2){
			$userInfo['lv']='免费版';
			$home_head=[
				[
					'jump'	=>	'/',
					'title'	=>	'<i class=\"layui-icon layui-icon-console\"></i> 控制面板'
				],
				[
					'jump'	=>	'/help',
					'title'	=>	'帮助文档'
				]
			];
		}
		$shop_head=[
				[
					'jump'	=>	'/shop',
					'title'	=>	'账户升级'
				]
			];
			$open_web_head = [
    [
        'jump' => '/open/web/fav',
        'title' => '网站favicon图标'
    ]
];

$open_music_head = [
    [
        'jump' => '/openmusic/music/search',
        'title' => '搜索歌曲'
    ],[
        'jump' => '/openmusic/music/info',
        'title' => '歌曲信息'
    ],[
        'jump' => '/openmusic/music/url',
        'title' => 'MP3地址'
    ],[
        'jump' => '/openmusic/music/list',
        'title' => '歌单信息'
    ]
];

$open_head = [
    [
        'title' => '<i class="layui-icon layui-icon-website"></i>网站类',
        'name' => 'web',
        'list' => $open_web_head
    ],[
        'title' => '<i class="layui-icon layui-icon-headset"></i> 音乐类 [计费]',
        'name' => 'music',
        'list' => $open_music_head
    ]
];
		$players = Player::where('user_id', '=', $userInfo['uid'])->order('create_time desc')->select();
		$player_head=[];
		foreach($players as $k=>$v){
			$player_head[$k]=[
				'jump'	=>	'/Player/id/'.$players[$k]['id'],
				'title'	=>	'<i class="layui-icon layui-icon-play"></i>'.$players[$k]['name']
			];
		}
		if(!$player_head){
			$player_head=[[
				'jump'	=>	'/#',
				'title'	=>	'未添加播放器'
			]];
		}
		
		$sheets = SongSheet::where('user_id', '=', $userInfo['uid'])->order('create_time desc')->select();
		$sheet_head=[];
		foreach($sheets as $k=>$v){
			$sheet_head[$k]=[
				'jump'	=>	'/songSheet/id/'.$sheets[$k]['id'],
				'title'	=>	'<img src="/static/images/type/sdtj.png" class="typeico">'.$sheets[$k]['name']
			];
		}
		if(!$sheet_head){
			$sheet_head=[[
				'jump'	=>	'/#',
				'title'	=>	'未添加歌单'
			]];
		}
		$result = [
			'code' => 0,
			'data' => [
				[
					'title'	=>	$userInfo['lv'].'主页',
					'icon'	=>	'layui-icon-home',
					'list'	=>	$home_head,
				],[
					'title'	=>	'账户升级',
					'icon'	=>	'layui-icon-cart-simple',
					'list'	=>	$shop_head,
				],[
					'title'	=>	'播放器['.Player::where('user_id',$userInfo['uid'])->count().']',
					'icon'	=>	'layui-icon-set',
					'list'	=>	$player_head,
				],[
					'title'	=>	'歌单['.SongSheet::where('user_id',$userInfo['uid'])->count().']',
					'icon'	=>	'layui-icon-user',
					'list'	=>	$sheet_head,
				],[
					'title'	=>	'开放接口',
					'icon'	=>	'layui-icon-set',
					'list'	=>	$open_head,
				]
			],
			'config' =>	[
				'logo'	=>	'<i class="layui-icon layui-icon layui-icon-headset" style="font-weight:bold;font-size:20px;"></i><span style="font-weight: bold;font-size: 20px"> '.Config::get('web.webname').'</span>',
			],
			'msg'	=>	''
		];
        return json($result);
	}
	
	public function MycountList()
    {
		$this->checkLogin();
		$userInfo = Users::getLoginUser();
		$players=Player::where('user_id',$userInfo['uid'])->count();
		$plays = Player::where('user_id', '=', $userInfo['uid'])->order('endtime desc')->select();
		if($players==0){
			$plays='未添加播放器';
		}else{
			$plays=$plays[0]['name'];
		}
		$pies = $userInfo['pie'];
		$result = [
			'code' => 0,
			'data' => [
				'players'	=>	Player::where('user_id',$userInfo['uid'])->count(),
				'sheets'	=>	SongSheet::where('user_id',$userInfo['uid'])->count(),
				'pies'	=>	$userInfo['pie'],
				'plays'	=>	$plays
			],
			'msg'	=>	''
		];
        return json($result);
	}
	
	public function editPlayerSongSheet()
	{
		$this->checkLogin();
		$data = input('post.');
		$userInfo = Users::getLoginUser();
		if(Player::checkPlayerUid($data['playerId'],$userInfo['uid'])== true){
			$result = [
				'code' => -1,
				'msg' => '没有权限'
			];
		}else{
			Cache::delete('info'.$data['playerId']);
			
			$ids = request()->param('ids/a');
			
			// 删除之前的关联
			Db::name('player_song_sheet')->where('player_id',$data['playerId'])->delete();

			if($ids != null){
				$joins = [];
				for($i = 0;$i < count($ids);$i++){
					// 修复：原来不校验歌单归属，可以把别人的歌单挂到自己的播放器上
					if ($userInfo['power'] != 0 && SongSheet::checkSheetUid($ids[$i], $userInfo['uid']) == true) {
						continue;
					}
					$joins[$i] = ['player_id' => $data['playerId'],'song_sheet_id' => $ids[$i],'taxis'=>$i];
				}

				//插入编辑之后的数据
				Db::name('player_song_sheet')->insertAll($joins);
			}
			$result = [
				'code' => 0,
				'msg' => '保存成功[缓存已清除]'
			];
		}
        return json($result);
    }
	
	public function Player($act)
    {
		$this->checkLogin();
		$userInfo = Users::getLoginUser();
		switch ($act) {
            case 'add':
				$data = input('post.');
				$data['uid'] = $userInfo['uid'];
				$data['id']	= uniqid();
				$rule=[
					'name'  => 'require',
				];
				$message=[
					'name.require'=>'播放器名称不能为空',
				];
				$validate=new \think\Validate($rule,$message);
				if(!$validate->check($data)){
					$result = [
						'code' => -1,
						'msg' => $validate->getError()
					];
				}elseif($userInfo['pie'] < 1){
					$result = [
						'code' => -1,
						'msg' => '播放器配额不足',
					];
				}elseif(Player::add($data)){
					$pie['pie'] = $userInfo['pie'] - 1;
					Users::updateByUid($userInfo['uid'],$pie);
					$result = [
						'code' => 0,
						'id'	=>	$data['id'],
						'msg' => '创建播放器成功,请进行下一步'
					];
				}
			break;
			case 'auth':
				if (!domain_auth_enabled()) {
					// 功能开关关掉后，直接调接口也不受理
					$result = ['code' => -1, 'msg' => '域名授权功能已在「更多设置」里关闭'];
					break;
				}
				$data = input('post.');
				// 规范化：去掉 http(s):// 、路径、端口、结尾斜杠，统一小写
				$data['domain'] = strtolower(trim((string) $data['domain']));
				$data['domain'] = preg_replace('#^[a-z]+://#i', '', $data['domain']);
				$data['domain'] = preg_replace('#[/?].*$#', '', $data['domain']);
				$data['domain'] = preg_replace('#:[0-9]+$#', '', $data['domain']);
				$data['domain'] = trim($data['domain'], '.');
				if ($data['domain'] === '') {
					$result = ['code' => -1, 'msg' => '请输入要授权的域名'];
					break;
				}
				if(empty($data['remark'])){
					$data['remark']='无备注';
				}
				if ($userInfo['power'] != 0 && Player::checkPlayerUid($data['id'], $userInfo['uid']) == true) {
				    // 修复：原来不校验归属，任何登录用户都能操作他人的播放器授权
				    $result = ['code' => -1, 'msg' => '没有权限'];
				    break;
				}
				// 授权顶级域名后，它的二级域名默认都可用，不用再单独授权
				$coveredBy = '';
				$existings = PlayerAuth::where('player_id', '=', $data['id'])->column('domain');
				foreach ((array) $existings as $existsDomain) {
					$existsDomain = (string) $existsDomain;
					if ($existsDomain === '' || $existsDomain === $data['domain']) {
						continue;
					}
					$suffix = '.' . $existsDomain;
					if (substr($data['domain'], -strlen($suffix)) === $suffix) {
						$coveredBy = $existsDomain;
						break;
					}
				}
				if ($coveredBy !== '') {
					$result = ['code' => -1, 'msg' => '已经授权过 ' . $coveredBy . '，它的所有二级域名默认都可用，不用单独再授权'];
					break;
				}
				$check=PlayerAuth::where('player_id','=',$data['id'])
					->where('domain','=',$data['domain'])
					->find(); 
				$number=PlayerAuth::where('player_id','=',$data['id'])->count(); 
				if($userInfo['power']==2){
					if($number>=1){
						$result = [
							'code' => -1,
							'msg' => '免费版最多添加一个站点',
						];
					}else{
						if(!$check){
							PlayerAuth::add($data);
							$result = [
								'code' => 0,
								'msg' => '添加授权成功',
							];
						}else{
							$result = [
								'code' => -1,
								'msg' => '域名已存在',
							];
						}
					}
				}else{
					if(!$check){
						PlayerAuth::add($data);
						$result = [
							'code' => 0,
							'msg' => '添加授权成功',
						];
					}else{
						$result = [
							'code' => -1,
							'msg' => '域名已存在',
						];
					}
				}
			break;
			case 'delauth':
				if (!domain_auth_enabled()) {
					$result = ['code' => -1, 'msg' => '域名授权功能已在「更多设置」里关闭'];
					break;
				}
				$data = input('post.');
				if ($userInfo['power'] != 0 && Player::checkPlayerUid($data['id'], $userInfo['uid']) == true) {
				    // 修复：原来不校验归属，任何登录用户都能操作他人的播放器授权
				    $result = ['code' => -1, 'msg' => '没有权限'];
				    break;
				}
				$del=PlayerAuth::where('player_id','=',$data['id'])->where('domain','=',$data['domain'])->delete();
				if($del){
					$result = [
						'code' => 0,
						'msg' => '删除授权成功',
					];
				}else{
					$result = [
						'code' => -1,
						'msg' => '删除授权失败',
					];
				}
			break;
			case 'edit':
				$data = input('post.');
				Cache::delete('info'.$data['id']);
				if ($userInfo['power'] != 0 && Player::checkPlayerUid($data['id'], $userInfo['uid']) == true) {
				    // 修复：原来不校验归属，任何登录用户都能操作他人的播放器
				    $result = ['code' => -1, 'msg' => '没有权限'];
				    break;
				}
				if(!array_key_exists('phone_load',$data)){
					$data['phone_load']='0';
				}
				if(!array_key_exists('show_lrc',$data)){
					$data['show_lrc']='0';
				}
				if(!array_key_exists('jquery',$data)){
					$data['jquery']='0';
				}
				if(!array_key_exists('random_player',$data)){
					$data['random_player']='0';
				}
				if(!array_key_exists('auto_player',$data)){
					$data['auto_player']='0';
				}
				if(!array_key_exists('show_notes',$data)){
					$data['show_notes']='0';
				}
				if(!array_key_exists('showmsg',$data)){
					$data['showmsg']='0';
				}
				if(!array_key_exists('switchopen',$data)){
					$data['switchopen']='0';
				}
				if(!array_key_exists('show_greeting',$data)){
					$data['show_greeting']='0';
				}
				Player::where('id','=',$data['id'])->data($data)->update();
				$result = [
					'code' => 0,
					'msg' => '保存成功[已清理缓存]'
				];
			break;
			case 'del':
				$data = input('post.');
				Cache::delete('info'.$data['id']);
				$userInfo = Users::getLoginUser();
				// 删除歌单关联项
				if ($userInfo['power'] != 0 && Player::checkPlayerUid($data['id'], $userInfo['uid']) == true) {
				    // 修复：原来不校验归属，任何登录用户都能操作他人的播放器
				    $result = ['code' => -1, 'msg' => '没有权限'];
				    break;
				}
				PlayerSongSheet::where('player_id',$data['id'])->delete();
				// 删除播放器
				Player::where('id',$data['id'])->delete();
				$pie['pie'] =$userInfo['pie'] + 1;
				Users::updateByUid($userInfo['uid'],$pie);
				$result = [
					'code' => 0,
					'msg' => '删除成功'
				];
			break;
		}
        return json($result);
	}
	
	public function songSheet($act)
    {
		$this->checkLogin();
		$userInfo = Users::getLoginUser();
		switch ($act) {
            case 'add':
				$data = input('post.');
				$data['id']	= uniqid();
				$data['uid'] = $userInfo['uid'];
				$data['author'] = $userInfo['username'];
				$rule=[
					'name'  => 'require',
				];
				$message=[
					'name.require'=>'歌单名称不能为空',
				];
				$validate=new \think\Validate($rule,$message);
				if(!$validate->check($data)){
					$result = [
						'code' => -1,
						'msg' => $validate->getError()
					];
				}elseif(SongSheet::add($data)){
					$result = [
						'code' => 0,
						'id'	=>	$data['id'],
						'msg' => '添加成功',
					];
				}
			break;
			case 'edit':
				$data = input('post.');
				$rule=[
					'name'  => 'require',
				];
				$message=[
					'name.require'=>'歌单名称不能为空',
				];
				$validate=new \think\Validate($rule,$message);
				if(!$validate->check($data)){
					$result = [
						'code' => -1,
						'msg' => $validate->getError()
					];
				}elseif(SongSheet::checkSheetUid($data['id'],$userInfo['uid'])== true){
					$result = [
						'code' => -1,
						'msg' => '没有权限',
					];
				}else{
					$players = SongSheet::songSheetPlayers($data['id']);
					if(count($players) > 0){
						foreach ($players as $value){
							// 删除api缓存
							Cache::delete('info'.$value->player_id);
						}
					}
					SongSheet::sets($data);
					$result = [
						'code' => 0,
						'msg' => '保存成功[缓存已清除]',
					];
				}
			break;
			case 'del':
				$data = input('post.');
								if ($userInfo['power'] != 0 && SongSheet::checkSheetUid($data['id'], $userInfo['uid']) == true) {
				    // 修复：原来不校验归属，任何登录用户都能删除他人的歌单
				    $result = ['code' => -1, 'msg' => '没有权限'];
				    break;
				}
$players = SongSheet::songSheetPlayers($data['id']);
						if(count($players) > 0){
							foreach ($players as $value){
								// 删除api缓存
								Cache::delete('info'.$value->player_id);
							}
						}
				// 删除歌单音乐
				Song::where('song_sheet_id', $data['id'])->delete();
				// 删除歌单关联项
				PlayerSongSheet::where('song_sheet_id',$data['id'])->delete();
				// 删除歌单
				SongSheet::where('id',$data['id'])->delete();
				$result = [
					'code' => 0,
					'msg' => '删除成功'
				];
			break;
		}
        return json($result);
	}
	
	public function Song($act)
    {
		$this->checkLogin();
		$userInfo = Users::getLoginUser();
		switch ($act) {
            case 'info':
				$data = input('post.');
				$rule=[
					'songid'  => 'require',
				];
				$message=[
					'songid.require'=>'歌曲ID不能为空',
				];
				$validate=new \think\Validate($rule,$message);
				if(!$validate->check($data)){
					$result = [
						'code' => -1,
						'msg' => $validate->getError()
					];
				}else{
					$data2 = Song::findMusicInfo($data);
					if($data2==false){
						$result = [
							'code' => -1,
							'msg' => '未找到任何歌曲'
						];
					}else{
						$result = $data2;
					}	
				}
			break;
			case 'getPageSongs':
            $page = input('get.page', 1); // 获取当前页码，默认为1
            $limit = input('get.limit', 10); // 每页显示数量，默认为10
            $songSheetId = input('get.songSheetId'); // 获取歌单ID
            if ($userInfo['power'] != 0 && SongSheet::checkSheetUid($songSheetId, $userInfo['uid']) == true) {
                // 修复：原来不校验归属，任何登录用户都能读取他人的歌单
                $result = ['code' => -1, 'msg' => '没有权限'];
                break;
            }
            $songs = Song::where('song_sheet_id', $songSheetId)
                ->page($page, $limit)
                ->select();

            $total = Song::where('song_sheet_id', $songSheetId)->count(); // 总记录数

            $result = [
                'code' => 0,
                'msg' => '获取成功',
                'songs' => $songs,
                'total' => $total
            ];
            break;
			case 'check':
				$data = input('post.');
				$rule=[
					'name'  => 'require',
					'album_name'  => 'require',
					'artist_name'  => 'require',
					'album_cover'  => 'require',
					'location'  => 'require',
				];
				$message=[
					'name.require'=>'歌曲名称不能为空',
					'album_name.require'=>'专辑名称不能为空',
					'artist_name.require'=>'作者名称不能为空',
					'album_cover.require'=>'专辑封面地址不能为空',
					'location.require'=>'歌曲播放地址不能为空',
				];
				$validate=new \think\Validate($rule,$message);
				if(!$validate->check($data)){
					$result = [
								'code' => -1,
								'msg' => $validate->getError()
							];
				}else{
					$result = [
						'code' => 0,
						'name'  => $data['name'],
						'album_name'  => $data['album_name'],
						'artist_name'  => $data['artist_name'],
						'album_cover'  => $data['album_cover'],
						'location'  => $data['location'],
						'lyric'  => $data['lyric'],
					];
				}
			break;
			case 'save':
				$jsonData = $_POST['jsonData'] ?? '';
				$songSheetId = $_POST['songSheetId'] ?? '';
				if ($userInfo['power'] != 0 && SongSheet::checkSheetUid($songSheetId, $userInfo['uid']) == true) {
				    // 修复：原来不校验归属，任何登录用户都能操作他人的歌单
				    $result = ['code' => -1, 'msg' => '没有权限'];
				    break;
				}
				$array = json_decode($jsonData, true);
				if (!is_array($array)) {
					// 修复：原逻辑先删后插且不校验输入，jsonData 非法会把整张歌单的歌曲删空
					$result = ['code' => -1, 'msg' => '数据格式错误，未做任何修改'];
					break;
				}
				// 清除缓存
				$players = SongSheet::songSheetPlayers($songSheetId);
				if(count($players) > 0){
					foreach ($players as $value){
						// 删除api缓存
						Cache::delete('info'.$value->player_id);
					}
				}

				// 修复：删除 + 重新插入放进同一事务，避免中途失败导致歌单歌曲全部丢失
				Db::startTrans();
				try {
					Song::where('song_sheet_id', $songSheetId)->delete();
					if (!empty($array)) {
						foreach ($array as $key => $value) {
							$value['song_sheet_id'] = $songSheetId;
							$value['id'] = uniqid();
							$array[$key] = $value;
						}
						Db::name('song')->replace()->insertAll($array);
					}
					Db::commit();
					$result = ['code' => 0, 'msg' => '保存成功[已清除缓存]'];
				} catch (\Throwable $e) {
					Db::rollback();
					$result = ['code' => -1, 'msg' => '保存失败：' . $e->getMessage()];
				}
			break;
			case 'search':
				$data=input('get.');
				$s=$data['song_name'];
				switch ($data['type']) {
					case 'netease':
						$body = $this->encode_netease_data([
							'method' => 'POST',
							'url' => 'http://music.163.com/api/cloudsearch/pc',
							'params' => [
								's' => $s,
								'type' => 1,
								'offset' => 1 * 10 - 10,
								'limit' => 50
							]
						]);
						$data=send_get('http://music.163.com/api/linux/forward?eparams='.$body['eparams'],1,'http://music.163.com/');
						$arr=json_decode($data, true);
						$songs = [];
						foreach($arr['result']['songs'] as $value){
							$songs[] = array(
							'song_name' => $value['name'],
							'type' => 'netease',
							'id' => $value['id'],
							'artist_name' => $value['ar'][0]['name']
							);
						}
						break;
					case  'kugou':
						$data=send_get('http://mobilecdn.kugou.com/api/v3/search/song?keyword='.$s.'&format=json&page=1&pagesize=50',0,'http://m.kugou.com/v2/static/html/search.html');
						$arr=json_decode($data, true);
						$songs = [];
						foreach($arr['data']['info'] as $value){
							$songs[] = array(
							'song_name' => $value['songname'],
							'type' => 'kugou',
							'id' => $value['hash'],
							'artist_name' => $value['singername']
							);
						}
						break;
						case 'qq':
    $url = "https://u.y.qq.com/cgi-bin/musicu.fcg";
    $searchid = base64_encode(random_bytes(8));
    $post = json_encode([
        "comm" => [
            "_channelid" => "19",
            "_os_version" => "6.2.9200-2",
            "authst" => "Q_H_L_5tvGesDV1E9ywCVIuapBeYL7IYKKtbZErLj5HeBkyXeqXtjfQYhP5tg",
            "ct" => "19",
            "cv" => "1873",
            "guid" => "B69D8BC956E47C2B65440380380B7E9A",
            "patch" => "118",
            "psrf_access_token_expiresAt" => 1972395786,
            "psrf_qqaccess_token" => "E81C8E6DCB9C9CB4C443F36EF69C8437",
            "psrf_qqopenid" => "9DF150E2F0F95C5C3A9B54D5A80FEF1A",
            "psrf_qqunionid" => "79B1E758D09B3762FA03A8CDB9697899",
            "tmeAppID" => "qqmusic",
            "tmeLoginType" => 2,
            "uin" => "1569097443",
            "wid" => "0"
        ],
        "music.search.SearchCgiService" => [
            "method" => "DoSearchForQQMusicDesktop",
            "module" => "music.search.SearchCgiService",
            "param" => [
                "grp" => 1,
                "num_per_page" => 50,
                "page_num" => 1,
                "query" => $s,
                "remoteplace" => "txt.newclient.history",
                "search_type" => 0,
                "searchid" => $searchid
            ]
        ]
    ]);

    $headers = [
        "Content-Type: application/json; charset=UTF-8",
        "Charset: UTF-8",
        "Accept: */*",
        "User-Agent: Mozilla/5.0 (Linux; Android 6.0.1; OPPO R9s Plus Build/MMB29M; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/55.0.2883.91 Mobile Safari/537.36",
        "Connection: Keep-Alive",
        "Accept-Encoding: gzip",
        "Host: u.y.qq.com"
    ];
    $arr = [
        "post" => $post,
        "Header" => $headers
    ];
    $data = curl($url, $arr);
    $json = json_decode($data, true);
    
    $arr = $json["music.search.SearchCgiService"]["data"]["body"]["song"]["list"];
    $songs = [];
    foreach ($arr as $value) {
        $songs[] = [
            'song_name' => $value['title'],
            'type' => 'qq',
            'id' => $value['mid'],
            'artist_name' => implode('/', array_column($value['singer'], 'name'))
        ];
    }
    break;
				}
			$result['songs'] = $songs;
			break;
			case 'sheet':
    $data = input('post.');
    $rule = [
        'sheetid' => 'require',
        'type' => 'require'
    ];
    $message = [
        'sheetid.require' => '歌单ID不能为空',
        'type.require' => '类型不能为空' 
    ];
    
    $validate = new \think\Validate($rule, $message);
    
    if (!$validate->check($data)) {
        $result = [
            'code' => -1,
            'msg'  => $validate->getError()
        ];
    } else {
        $type = $data['type'] ?? null;
        
        if ($type === null) {
            $result = [
                'code' => -1,
                'msg'  => '类型不能为空'
            ];
        } else {
            $songs = Song::findSheetInfo($type, $data['sheetid']);
            
            if (empty($songs)) {
                $result = [
                    'code' => -1,
                    'msg'  => '未找到任何歌单'
                ];
            } else {
                $result = [
                    'code'  => 0,
                    'msg'   => '添加成功',
                    'songs' => $songs
                ];
            }
        }
    }
    break;
		}
        return json($result);
	}
	
	public function play($id)
    {
		$this->checkLogin();
		$player=Player::where('id',$id)->find();
		$data=[
			'plays'	=>	$player['plays']+1,
			'endtime'	=>	date('Y-m-d H:i:s')
		];
		Player::where('id','=',$id)->data($data)->update();
		$data2=[
			'player_id'	=>	$id,
			'user_id'	=>	'1',
			'side'	=>	'ios',
			'create_time'	=>	date('Y-m-d H:i:s')
		];
		Plays::add($data2);
		$result = [
			'code' => 0,
			'msg'	=>	'成功模拟播放['.$player['name'].']'
		];
        return json($result);
	}
	

public function clearCache()
{
    $this->checkLogin();

    // 尝试清除所有缓存
    if (Cache::clear()) {
        $result = [
            'code' => 0,
            'msg'  => '成功清除所有缓存'
        ];
    } else {
        $result = [
            'code' => -1,
            'msg'  => '清除缓存失败'
        ];
    }

    return json($result);
}

	public function load()
{
    $this->checkLogin();
    $userInfo = Users::getLoginUser();
    if (!$userInfo || !isset($userInfo['uid'])) {
        return json(['code' => 1, 'msg' => '用户信息无效']);
    }

    $plays = Plays::where(1)->order('create_time desc')->select();
    $play = 0;
    $Todayplay = 0;
    $currentDate = date('Y-m-d');

    if (!$plays->isEmpty()) {
        foreach ($plays as $playEntry) {
            if (isset($playEntry['create_time'])) {
                $b = substr($playEntry['create_time'], 0, 10);
                if ($b === $currentDate) {
                    $Todayplay++;
                }
                $play++;
            }
        }
    }

    $meplays = Plays::where('user_id', $userInfo['uid'])->order('create_time desc')->select();
    $meplay = 0;
    $meTodayplay = 0;

    if (!$meplays->isEmpty()) {
        foreach ($meplays as $meplayEntry) {
            if (isset($meplayEntry['create_time'])) {
                $b = substr($meplayEntry['create_time'], 0, 10);
                if ($b === $currentDate) {
                    $meTodayplay++;
                }
                $meplay++;
            }
        }
    }

    $playersCount = Player::count();
    if ($playersCount == 0) {
        $newplay = '无数据';
        $newplaytime = date('Y-m-d H:i:s');
    } else {
        $newplay = Player::order('endtime desc')->limit(1)->select();
        if (!$newplay->isEmpty()) {
            $newplayData = $newplay->first();
            $newplay = $newplayData['name'] ?? '无数据';
            $newplaytime = $newplayData['endtime'] ?? date('Y-m-d H:i:s');
        } else {
            $newplay = '无数据';
            $newplaytime = date('Y-m-d H:i:s');
        }
    }

    $newplays = Player::order('endtime desc')->limit(6)->select();
    $newplaylist = '';

    if (!$newplays->isEmpty()) {
        foreach ($newplays as $k => $newplayEntry) {
            if (isset($newplayEntry['user_id'])) {
                $user = Users::where('uid', $newplayEntry['user_id'])->find();
                if ($user) {
                    $lv = $this->getUserLevel($user['power']);
                    $color = $this->getRowColor($k);
                    $newplaylist .= '<tr><td><span class="'.$color.'"><img src="https://q2.qlogo.cn/headimg_dl?bs=qq&dst_uin='.$user['qq'].'&spec=100" width="20px" style="border-radius: 5px;"> '.$newplayEntry['name'].'</span></td><td><span class="'.$color.'">'.date('H:i:s', strtotime($newplayEntry['endtime'])).'</span></td><td><span class="'.$color.'">'.$lv.'</span></td></tr>';
                }
            }
        }
    }

    $newlogin = Users::order('time desc')->limit(6)->select();
    $newloginlist = '';

    if (!$newlogin->isEmpty()) {
        foreach ($newlogin as $k => $loginEntry) {
            $lv = $this->getUserLevel($loginEntry['power']);
            $time = isset($loginEntry['time']) ? $loginEntry['time'] : '1599123926';
            $color = $this->getRowColor($k);
            $newloginlist .= '<tr><td><span class="'.$color.'"><img src="https://q2.qlogo.cn/headimg_dl?bs=qq&dst_uin='.$loginEntry['qq'].'&spec=100" width="20px" style="border-radius: 5px;"> '.hideStr($loginEntry['username'], 2, 2).'</span></td><td><span class="'.$color.'">'.date('H:i:s', $time).'</span></td><td><span class="'.$color.'">'.$lv.'</span></td></tr>';
        }
    }

    $os_name = PHP_OS;
    if (strpos($os_name, "Linux") !== false) {
        // 修复：原来这里被整段注释掉了，实时监控的 CPU / 内存 / 负载 永远是空的（进度条不动）
        $count = $this->getLinuxCount();
        $result = [
            'code' => 0,
            'data' => [
                'mem' => $count['mem'],
                'memRealUsed' => $count['memRealUsed'],
                'memTotal' => $count['memTotal'],
                'mems' => 'layui-progress-bar layui-bg-red',
                'cpu' => $count['cpu'],
                'cpus' => 'layui-progress-bar',
                'load' => $count['load_avg'],
                'loads' => 'layui-progress-bar',
                'loadRaw' => $count['load_raw'],
                'cores' => $count['cores'],
                'play' => whits($play),
                'todayplay' => whits($Todayplay),
                'meplay' => whits($meplay),
                'metodayplay' => whits($meTodayplay),
                'newplay' => $newplay,
                'newplaytime' => $newplaytime,
                'newplaylist' => $newplaylist,
                'newloginlist' => $newloginlist
            ],
            'msg' => ''
        ];
    } elseif (strpos($os_name, "WIN") !== false) {
        $mem = $this->getMemoryUsage();
        $result = [
            'code' => 0,
            'data' => [
                'mem' => $mem['usage'].'%',
                'memRealUsed' => getFilesize($mem['TotalVisibleMemorySize'] - $mem['FreePhysicalMemory']),
            /*    'memTotal' => getFilesize($mem['TotalVisibleMemorySize']),
                'mems' => 'layui-progress-bar layui-bg-red',
                'cpu' => $this->getCpuUsage().'%',
                'cpus' => 'layui-progress-bar',*/
                'play' => whits($play),
                'todayplay' => $Todayplay,
                'meplay' => whits($meplay),
                'metodayplay' => $meTodayplay,
                'newplay' => $newplay,
                'newplaytime' => $newplaytime,
                'newplaylist' => $newplaylist,
                'newloginlist' => $newloginlist
            ],
            'msg' => ''
        ];
    }

    return json($result);
}

private function getUserLevel(int $power): string
{
    switch ($power) {
        case 0:
            return '管理员';
        case 1:
            return '付费版';
        default:
            return '免费版';
    }
}

private function getRowColor(int $index): string
{
    switch ($index) {
        case 0:
            return 'first';
        case 1:
            return 'second';
        case 2:
            return 'third';
        default:
            return '';
    }
}
	
		// 新增：发送测试邮件（仅管理员），用于验证邮箱配置是否可用
	public function moresetTestMail()
	{
		$this->checkLogin();
		$curUser = Users::getLoginUser();
		if (($curUser['power'] ?? -1) !== 0) {
			return json(['code' => -1, 'msg' => '没有权限']);
		}
		$email = trim((string) input('post.email'));
		if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return json(['code' => -1, 'msg' => '请填写正确的收件邮箱']);
		}
		$result = SendEmail::SendEmail('SMTP 测试邮件', '如果你收到这封邮件，说明邮箱配置已经可用，验证码功能同时生效。', $email);
		if ($result === true) {
			return json(['code' => 0, 'msg' => '测试邮件已发出，请到 ' . $email . ' 查收（含垃圾箱）']);
		}
		return json(['code' => -1, 'msg' => '发送失败：' . (is_string($result) ? $result : '未知错误')]);
	}

public function config()
    {
		$this->checkLogin();
		// 修复：网站设置属于管理员权限，原来只要登录就能改全站配置
		$curUser = Users::getLoginUser();
		if (($curUser['power'] ?? -1) !== 0) {
			return json(['code' => -1, 'msg' => '没有权限']);
		}
		// 修复：原为字符串拼接 SQL（键值均可注入）；改为参数绑定 + 键名白名单
		foreach (input('post.') as $k => $value) {
			if (!is_scalar($value) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', (string) $k)) {
				continue;
			}
			Db::execute('INSERT INTO gui_configs SET k=?, v=? ON DUPLICATE KEY UPDATE v=?', [$k, (string) $value, (string) $value]);
		}
		$result = [
			'code' => 0,
			'message' => '保存成功', 'msg' => '保存成功'
		];
        return json($result);
    }

	// 留言管理：列表 / 删除（只给管理员）
	public function chat($act)
    {
		$this->checkLogin();
		$userInfo = Users::getLoginUser();
		if (($userInfo['power'] ?? -1) !== 0) {
			return json(['code' => -1, 'msg' => '没有权限']);
		}
		switch ($act) {
			case 'list':
				$page = intval(input('get.page', 1));
				$page = $page > 0 ? $page : 1;
				$limit = intval(input('get.limit', 15));
				if ($limit < 1 || $limit > 100) { $limit = 15; }
				$keyword = trim((string) input('get.keyword', ''));
				$build = function () use ($keyword) {
					$query = Chat::order('id', 'desc');
					if ($keyword !== '') {
						$query = $query->where(function ($sub) use ($keyword) {
							$sub->where('nickname', 'like', '%' . $keyword . '%')->whereOr('content', 'like', '%' . $keyword . '%');
						});
					}
					return $query;
				};
				$count = intval($build()->count());
				$rows = $build()->page($page, $limit)->select();
				$rows = (is_object($rows) && method_exists($rows, 'toArray')) ? $rows->toArray() : (array) $rows;
				$data = [];
				foreach ($rows as $row) {
					$data[] = [
						'id' => intval($row['id']),
						'uid' => intval($row['uid']),
						'nickname' => (string) $row['nickname'],
						'qq' => (string) $row['qq'],
						'content' => (string) $row['content'],
						'sendip' => (string) $row['sendip'],
						'sendtime' => (string) ($row['sendtime'] ?: $row['time']),
					];
				}
				$result = ['code' => 0, 'msg' => '', 'count' => $count, 'data' => $data];
			break;
			case 'del':
				$ids = [];
				$one = input('post.id', '');
				if ($one !== '' && $one !== null) { $ids[] = intval($one); }
				$many = (string) input('post.ids', '');
				if ($many !== '') {
					foreach (explode(',', $many) as $v) { $ids[] = intval($v); }
				}
				$ids = array_values(array_unique(array_filter($ids, function ($v) { return $v > 0; })));
				if (!$ids) {
					$result = ['code' => -1, 'msg' => '没有要删除的留言'];
					break;
				}
				$n = intval(Chat::where('id', 'in', $ids)->delete());
				$result = ['code' => 0, 'msg' => '删除成功（' . $n . ' 条）'];
			break;
			default:
				$result = ['code' => -1, 'msg' => '未知操作'];
			break;
		}
		return json($result);
	}

	
	public function userInfo()
    {
		$this->checkLogin();
		$userInfo = Users::getLoginUser();
		if($userInfo['power']==0){
			$userInfo['lv']='系统管理员';
		}elseif($userInfo['power']==1){
			$userInfo['lv']='付费版用户';
		}elseif($userInfo['power']==2){
			$userInfo['lv']='免费版用户';
		}
		$result = [
			'code' => 0,
			'data' => $userInfo,
			'msg'	=>	''
		];
		
        return json($result);
	}
	
	public function password()
	{
		$this->checkLogin();
		$userInfo = Users::getLoginUser();
		$data = input('post.');
		$result = Users::change_Password($userInfo['uid'],$data);
        return json($result);
    }
    
    public function changeQQ()
{
    $this->checkLogin();
    $userInfo = Users::getLoginUser();
    $newQQ = input('post.newQQ');
    
    if (empty($newQQ) || !preg_match('/^\d{5,20}$/', $newQQ)) {
        return json([
            'code' => -1,
            'message' => 'QQ号格式不正确', 'msg' => 'QQ号格式不正确'
        ]);
    }
    
    $result = Users::updateQQ($userInfo['uid'], $newQQ);
    return json($result);
}
	
	protected $userInfo;
    private
    function checkLogin()
    {
        $check_Login = Users::checkLogin();
        if ($check_Login !== null) {
            View::assign('alert', $check_Login['msg']);
            View::assign('url', $check_Login['url']);
            exit(View::fetch('common/error'));
        } else {
            $this->userInfo = Users::getLoginUser();
            View::assign('userInfo', $this->userInfo);
        }
    }
}
