<?php
namespace app\controller;

use app\BaseController;
use think\facade\View;
use app\model\Users;
use think\facade\Session;
use think\facade\Request;
use think\facade\Cookie;
use think\middleware\SessionInit;
use PHPMailer\SendEmail;
use app\util\geetest\GeetestLib;
use think\response\Json;
use think\Validate;

class LoginAjax extends Common
{
    // 读取后台「更多设置」里的数值配置：未设置用默认值，显式设为 0 表示关闭该限制
    private function numConf($key, $default)
    {
        $v = \think\facade\Config::get('web.' . $key, '');
        return ($v === '' || $v === null) ? $default : (int) $v;
    }

    public function login()
{
    $data = input('post.');
    // 修复：完全没有提交数据时（例如直接访问接口地址），Validate 对空数组会放行，
    // 后面 $data['username'] 取不到值会直接 500，这里先给出正常提示
    if (!isset($data['username']) || !isset($data['password'])) {
        return json(['code' => -1, 'msg' => '请输入用户名和密码']);
    }
    // 修复：登录失败限速，防撞库（同一 用户名+IP 15 分钟内失败 8 次则暂时锁定）
    $failLimit = $this->numConf('login_fail_limit', 8);
    $lockMin = $this->numConf('login_lock_minutes', 15);
    $failKey = 'loginfail_' . md5(strtolower(trim((string) ($data['username'] ?? ''))) . '|' . real_ip());
    if ($failLimit > 0 && (int) \think\facade\Cache::get($failKey) >= $failLimit) {
        return json(['code' => -1, 'msg' => '登录失败次数过多，请 ' . ($lockMin > 0 ? $lockMin . ' 分钟后再试' : '稍后再试')]);
    }
    
    // 从请求中获取腾讯验证码的 ticket 和 randstr
    $ticket = $data['ticket'] ?? '';
    $randstr = $data['randstr'] ?? '';
    
    // 验证腾讯验证码
    // 上游 urlsec 接口已失效（恒返回 reCode=-101 → 本方法返回 -1），
    // 原逻辑把"接口失效"当成"验证码错误"会永久阻断登录；
    // 现仅在明确判定验证码错误（返回 0）时拒绝。
    $verificationResult = $this->verifyTencentCaptcha($ticket, $randstr);
    if ($verificationResult === 0) {
        $result['msg'] = '验证码效验失败，请重试';
        return json($result);
    }
    
    // 其他登录逻辑
    $rule = [
        'username'  => 'require|alphaDash|length:5,20',
        'password'  => 'require|alphaNum|length:5,20',
    ];
    $message = [
        'username.require' => '用户名不能为空',
        'username.length' => '用户名长度必须在5到20之间',
        'password.require' => '密码不能为空',
        'password.alphaNum' => '密码只能字母和数字',
        'password.length' => '密码长度必须在5到20之间',
    ];
    $validate = new \think\Validate($rule, $message);
    if (!$validate->check($data)) {
        $result = [
            'code' => -1,
            'msg' => $validate->getError()
        ];
    } elseif (Users::checkUsername($data['username']) == true) {
        $result = [
            'code' => -1,
            'msg' => '用户名不存在'
        ];
    } elseif (Users::checkPass($data) == false) {
        $result = [
            'code' => -1,
            'msg' => '账号或密码错误'
        ];
    } elseif (Users::login($data)) {
        $result = [
            'code' => 0,
            'msg' => '登录成功'
        ];
    } else {
        $result = [
            'code' => -1,
            'msg' => '登录失败'
        ];
    }

    // 修复：失败累计、成功清零（配合上面的限速）
    if (($result['code'] ?? -1) === 0) {
        \think\facade\Cache::delete($failKey);
    } elseif (!empty($data['username']) && $failLimit > 0) {
        \think\facade\Cache::set($failKey, (int) \think\facade\Cache::get($failKey) + 1, ($lockMin > 0 ? $lockMin : 15) * 60);
    }

    return json($result);
}

// 腾讯验证码验证
private function verifyTencentCaptcha($ticket, $randstr)
{
    // 修复：上游 urlsec 接口早已失效，没有 ticket 时不必再发这一次请求
    // （原来每次登录都要等这个接口，慢则直接把登录请求挂住）
    if (trim((string) $ticket) === '' && trim((string) $randstr) === '') {
        return -1;
    }
    $url = 'https://cgi.urlsec.qq.com/index.php?m=check&a=gw_check&callback=url_query&url=https%3A%2F%2Fwww.qq.com%2F' . rand(111111, 999999) . '&ticket=' . urlencode($ticket) . '&randstr=' . urlencode($randstr);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $httpheader[] = "Accept: application/json";
    $httpheader[] = "Accept-Language: zh-CN,zh;q=0.8";
    $httpheader[] = "Connection: close";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
    curl_setopt($ch, CURLOPT_REFERER, 'https://urlsec.qq.com/check.html');
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.198 Safari/537.36');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    // 修复：原来没有超时，上游不响应时登录会一直挂着
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $ret = curl_exec($ch);
    curl_close($ch);

  //  $arr = jsonp_decode($ret, true);
  $arr = $this->jsonp_decode($ret, true);
    if (isset($arr['reCode']) && $arr['reCode'] == 0) {
        return 1; // 验证通过
    } elseif ($arr['reCode'] == -109) {
        return 0; // 验证码错误
    } else {
        return -1; // 接口已失效
    }
}
function jsonp_decode($jsonp, $assoc = false)
{
	$jsonp = trim($jsonp);
	if(isset($jsonp[0]) && $jsonp[0] !== '[' && $jsonp[0] !== '{') {
		$begin = strpos($jsonp, '(');
		if(false !== $begin)
		{
			$end = strrpos($jsonp, ')');
			if(false !== $end)
			{
				$jsonp = substr($jsonp, $begin + 1, $end - $begin - 1);
			}
		}
	}
	return json_decode($jsonp, $assoc);
}
    
    public function reg()
    {
        $data = input('');
        $rule = [
            'username'  => 'require|alphaDash|length:5,20',
            'password'  => 'require|alphaNum|length:5,20',
            'qq'    => 'require|number|length:4,13',
            'mail'    => 'require',
            'code'    => 'require',
        ];
        $message = [
            'username.require' => '用户名不能为空',
            'username.length' => '用户名长度必须在5到20之间',
            'username.alphaDash' => '用户名只能是字母、数字和下划线_及破折号-',
            'password.require' => '密码不能为空',
            'password.alphaNum' => '密码只能字母和数字',
            'password.length' => '密码长度必须在5到20之间',
            'qq.require' => "QQ不能为空",
            'qq.number' => "QQ只能数字",
            'qq.length' => "QQ长度必须在4到13之间",
            'mail.require' => "邮箱账号不能为空",
            'code.require' => "邮箱验证码不能为空",
        ];
        
        $validate = new \think\Validate($rule, $message);
        if (!$validate->check($data)) {
            $result = [
                'code' => -1,
                'msg' => $validate->getError()
            ];
        } elseif ($this->checkcode(($data['code'] ?? ''), ($data['mail'] ?? '')) != true) {
            $result = [
                'code' => -1,
                'msg' => '邮箱验证码错误'
            ];
        } elseif (Users::checkUsername($data['username']) != true) {
            $result = [
                'code' => -1,
                'msg' => '用户名已被注册'
            ];
        } elseif (Users::checkQq($data['qq']) != true) {
            $result = [
                'code' => -1,
                'msg' => 'QQ已被其他用户绑定'
            ];
        } elseif (Users::where('mail', '=', ($data['mail'] ?? ''))->find()) {
            $result = [
                'code' => -1,
                'msg' => '此邮箱已被其他用户绑定'
            ];
        } elseif (Users::reg($data)) {
            $result = [
                'code' => 0,
                'msg' => '注册成功'
            ];
        }
        return json($result); // 修复：返回数组在非 JSON 请求下会 500
    }
    
    public function find()
    {
        $data = input('');
        $rule = [
            'password'  => 'require|alphaNum|length:5,20',
            'mail'    => 'require',
            'code'    => 'require',
        ];
        $message = [
            'password.require' => '密码不能为空',
            'password.alphaNum' => '密码只能字母和数字',
            'password.length' => '密码长度必须在5到20之间',
            'mail.require' => "邮箱账号不能为空",
            'code.require' => "邮箱验证码不能为空",
        ];
        
        $validate = new \think\Validate($rule, $message);
        if (!$validate->check($data)) {
            $result = [
                'code' => -1,
                'msg' => $validate->getError()
            ];
        } elseif ($this->checkcode(($data['code'] ?? ''), ($data['mail'] ?? '')) != true) {
            $result = [
                'code' => -1,
                'msg' => '邮箱验证码错误'
            ];
        } elseif (!Users::where('mail', '=', ($data['mail'] ?? ''))->find()) {
            $result = [
                'code' => -1,
                'msg' => '此邮箱未被任何用户绑定'
            ];
        } elseif (Users::findpassword($data)) {
            $result = [
                'code' => 0,
                'msg' => '重置密码成功'
            ];
        }
        return json($result); // 修复：返回数组在非 JSON 请求下会 500
    }
    
    public function sendcode()
    {
        if (!\think\facade\Request::instance()->isAjax()) {
            return json(['code' => -1, 'msg' => '非法请求']);
        }
        $email = trim((string) input('email'));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json(['code' => -1, 'msg' => '请填写正确的邮箱地址']);
        }
        // 修复：验证码改为服务端存储并与邮箱绑定、一次性使用，同时限制发送频率
        // （原来只把 md5(验证码) 写进 Cookie，既不限次数、也不绑定邮箱）
        $cooldown = $this->numConf('regcode_cooldown', 60);
        $ipLimit = $this->numConf('regcode_ip_limit', 10);
        if ($cooldown > 0 && \think\facade\Cache::get('mailcode_cd_' . md5($email))) {
            return json(['code' => -1, 'msg' => '验证码发送过于频繁，请 ' . $cooldown . ' 秒后再试']);
        }
        $ipKey = 'mailcode_ip_' . md5(real_ip());
        $ipCount = (int) \think\facade\Cache::get($ipKey);
        if ($ipLimit > 0 && $ipCount >= $ipLimit) {
            return json(['code' => -1, 'msg' => '发送次数过多，请稍后再试']);
        }
        $code = rand(100000, 999999);
        $msg = '尊敬的用户，您正在进行邮箱验证，本次请求的验证码为：' . $code;
        $result = SendEmail::SendEmail('邮箱验证码', $msg, $email);
        // 修复：SendEmail 失败时返回的是错误信息字符串（非空字符串为真），
        // 原来用 if ($result) 判断，导致发信失败也提示"已发送"
        if ($result === true) {
            \think\facade\Cache::set('mailcode_' . md5($email), ['code' => (string) $code, 'tries' => 0], 600);
            if ($cooldown > 0) {
                \think\facade\Cache::set('mailcode_cd_' . md5($email), 1, $cooldown);
            }
            \think\facade\Cache::set($ipKey, $ipCount + 1, 3600);
            return json(['code' => 0, 'msg' => '已发送验证码到邮箱' . $email]);
        }
        // 失败原因写日志便于排查，但不把 SMTP 细节返回给前台
        \think\facade\Log::error('验证码邮件发送失败：' . (is_string($result) ? $result : '未知错误'));
        return json(['code' => -1, 'msg' => '发送验证码失败，请联系管理员检查邮箱配置']);
    }
    
    // 修复：这是内部校验方法，不对外提供路由（原来 public + 无默认参数，直接访问会 500）
    protected function checkcode($code = '', $email = '')
    {
        // 修复：验证码服务端存储、与邮箱绑定、一次性使用、hash_equals 严格比较
        // （原来的 md5($code) == Cookie 松散比较可被 0e 魔术哈希绕过，且不校验邮箱归属）
        $email = trim((string) $email);
        $code = trim((string) $code);
        if ($email === '' || $code === '') {
            return false;
        }
        $key = 'mailcode_' . md5($email);
        $entry = \think\facade\Cache::get($key);
        if (!is_array($entry) || empty($entry['code'])) {
            return false;
        }
        if (($entry['tries'] ?? 0) >= 5) {
            \think\facade\Cache::delete($key);
            return false;
        }
        if (!hash_equals((string) $entry['code'], $code)) {
            $entry['tries'] = ($entry['tries'] ?? 0) + 1;
            \think\facade\Cache::set($key, $entry, 600);
            return false;
        }
        \think\facade\Cache::delete($key);
        return true;
    }
}
