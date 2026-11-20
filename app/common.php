<?php

function curl($url, $paras = [])
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    if (isset($paras['Header'])) {
        $Header = $paras['Header'];
    } else {
        $Header[] = 'Accept:*/*';
        $Header[] = 'Accept-Encoding:gzip,deflate,sdch';
        $Header[] = 'Accept-Language:zh-CN,zh;q=0.8';
        $Header[] = 'Connection:close';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $Header);
    if (isset($paras['ctime'])) {
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $paras['ctime']);
    } else {
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    }
    if (isset($paras['rtime'])) {
        curl_setopt($ch, CURLOPT_TIMEOUT, $paras['rtime']);
    } else {
        // 修复：原来没有总超时，上游不响应时请求会一直挂着
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    }
    if (isset($paras['post'])) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $paras['post']);
    }
    if (isset($paras['header'])) {
        curl_setopt($ch, CURLOPT_HEADER, true);
    }
    if (isset($paras['cookie'])) {
        curl_setopt($ch, CURLOPT_COOKIE, $paras['cookie']);
    }
    if (isset($paras['refer'])) {
        if ($paras['refer'] == 1) {
            curl_setopt($ch, CURLOPT_REFERER, 'http://m.qzone.com/infocenter?g_f=');
        } else {
            curl_setopt($ch, CURLOPT_REFERER, $paras['refer']);
        }
    }
    if (isset($paras['ua'])) {
        curl_setopt($ch, CURLOPT_USERAGENT, $paras['ua']);
    } else {
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/65.0.3325.181 Safari/537.36');
    }
    if (isset($paras['nobody'])) {
        curl_setopt($ch, CURLOPT_NOBODY, 1);
    }
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    if (isset($paras['GetCookie'])) {
        curl_setopt($ch, CURLOPT_HEADER, 1);
        $result = curl_exec($ch);
        preg_match_all('/Set-Cookie: (.*?);/m', $result, $matches);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($result, 0, $headerSize);
        $body = substr($result, $headerSize);
        $ret = ['cookie' => $matches, 'body' => $body, 'Header' => $header, 'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE)];
        curl_close($ch);
        return $ret;
    }
    $ret = curl_exec($ch);
    if (isset($paras['loadurl'])) {
        $Headers = curl_getinfo($ch);
        if (isset($Headers['redirect_url'])) {
            $ret = $Headers['redirect_url'];
        } else {
            $ret = false;
        }
    }
    curl_close($ch);
    return $ret;
}
function send_get($url, $post = 0, $referer = 0, $cookie = 0, $header = 0, $ua = 0, $nobaody = 0)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $httpheader[] = 'Accept:application/json';
    $httpheader[] = 'Accept-Encoding:gzip,deflate,sdch';
    $httpheader[] = 'Accept-Language:zh-CN,zh;q=0.8';
    $httpheader[] = 'Connection:close';
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheader);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    if ($header) {
        curl_setopt($ch, CURLOPT_HEADER, true);
    }
    if ($cookie) {
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
    }
    if ($referer) {
        if ($referer == 1) {
            curl_setopt($ch, CURLOPT_REFERER, 'http://m.qzone.com/infocenter?g_f=');
        } else {
            curl_setopt($ch, CURLOPT_REFERER, $referer);
        }
    }
    if ($ua) {
        curl_setopt($ch, CURLOPT_USERAGENT, $ua);
    } else {
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (MSIE 9.0; Windows NT 6.1; Trident/5.0)');
    }
    if ($nobaody) {
        curl_setopt($ch, CURLOPT_NOBODY, 1);
    }
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    // 修复：send_get 原来完全没有超时设置，上游无响应会一直占用 PHP 进程
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $ret = curl_exec($ch);
    curl_close($ch);
    return $ret;
}
function Post($curlPost, $url)
{
    $curlPost = http_build_query($curlPost);
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_NOBODY, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $curlPost);
    $return = curl_exec($curl);
    curl_close($curl);
    return $return;
}
function GetCurl($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $rtn = curl_exec($ch);
    curl_exec($ch);
    return $rtn;
}
function daddslashes($string, $force = 0, $strip = false)
{
    !defined('MAGIC_QUOTES_GPC') && define('MAGIC_QUOTES_GPC', get_magic_quotes_gpc());
    if (!MAGIC_QUOTES_GPC || $force) {
        if (is_array($string)) {
            foreach ($string as $key => $val) {
                $string[$key] = daddslashes($val, $force, $strip);
            }
        } else {
            $string = addslashes($strip ? stripslashes($string) : $string);
        }
    }
    return $string;
}
function check_Hash($value, $hash)
{
    return password_verify($value, $hash);
}
function make_Hash($value)
{
    return password_hash($value, PASSWORD_DEFAULT);
}
function real_ip()
{
    $ip = $_SERVER['REMOTE_ADDR'];
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && preg_match_all('#\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}#s', $_SERVER['HTTP_X_FORWARDED_FOR'], $matches)) {
        foreach ($matches[0] as $xip) {
            if (!preg_match('#^(10|172\.16|192\.168)\.#', $xip)) {
                $ip = $xip;
                break;
            }
        }
    } elseif (isset($_SERVER['HTTP_CLIENT_IP']) && preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (isset($_SERVER['HTTP_X_REAL_IP']) && preg_match('/^([0-9]{1,3}\.){3}[0-9]{1,3}$/', $_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    }
    return $ip;
}
function ip_city_str($str)
{
    return str_replace(['省', '市'], '', $str);
}
// 首页主题清单（后台「主题设置」与首页共用同一份清单）
    // 主题清单：首页主题 + 登录页配色（后台「主题设置」用）
    function home_themes()
    {
        return [
            'minimal' => [
                'name' => "极简白", 'desc' => "瑞士网格：大留白 + 细线分隔 + 编号小节，整页没有卡片盒子",
                'c1' => '#ffffff', 'c2' => '#111111', 'c3' => '#e4572e', 'mock' => 'bars',
                'accent' => '#e4572e', 'ink' => '#111111', 'card' => '#ffffff',
                'auth' => ".layadmin-user-login{background:#ffffff!important;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif}.layadmin-user-login-main{background:#ffffff!important;border:1px solid #e8e8e8;border-radius:2px;box-shadow:none;padding:30px 32px 24px}.layadmin-user-login-main:before{content:\"\";display:block;height:6px;margin:-30px -32px 22px;border-radius:2px 2px 0 0;background:linear-gradient(90deg,#e4572e,#111111)}.layadmin-user-login-header h2{color:#111111!important;font-weight:800;font-size:24px}.layadmin-user-login-header p{color:#6f6f6f!important}.layadmin-user-login-box .layui-input{background:#ffffff!important;border-color:#e8e8e8!important;color:#111111!important;border-radius:2px}.layadmin-user-login-box .layui-input:focus{border-color:#e4572e!important}.layadmin-user-login-icon{color:#6f6f6f}.layadmin-user-login-box .layui-btn{background:#e4572e!important;color:#ffffff!important;border-radius:2px;font-weight:600;border:none}.layadmin-user-login-box .layui-btn:hover{filter:brightness(1.08)}.layadmin-user-login-other,.layadmin-user-login-other label{color:#6f6f6f}.layadmin-user-login-other a{color:#e4572e!important}.layadmin-user-login-footer p,.layadmin-user-login-footer a{color:#6f6f6f!important}.layadmin-user-login-main{border-radius:0;box-shadow:none;border:1px solid #e8e8e8}.layadmin-user-login-main:before{border-radius:0}.layadmin-user-login-header h2{letter-spacing:-1px}",
            ],
            'magazine' => [
                'name' => "复古杂志", 'desc' => "报纸头版：报头 + 三栏长文 + 首字下沉 + 风格索引",
                'c1' => '#f4efe6', 'c2' => '#3b2f2f', 'c3' => '#b3541e', 'mock' => 'cols',
                'accent' => '#b3541e', 'ink' => '#2f2620', 'card' => '#fffdf8',
                'auth' => ".layadmin-user-login{background:#f4efe6;background-image:radial-gradient(rgba(47,38,32,.05) 1px,transparent 1px);background-size:6px 6px!important;font-family:Georgia,'Times New Roman','Songti SC','STSong',SimSun,serif}.layadmin-user-login-main{background:#fffdf8!important;border:1px solid rgba(47,38,32,.18);border-radius:0;box-shadow:none;padding:30px 32px 24px}.layadmin-user-login-main:before{content:\"\";display:block;height:6px;margin:-30px -32px 22px;border-radius:0 0 0 0;background:linear-gradient(90deg,#b3541e,#2f2620)}.layadmin-user-login-header h2{color:#2f2620!important;font-weight:800;font-size:24px}.layadmin-user-login-header p{color:#7b6a5b!important}.layadmin-user-login-box .layui-input{background:#ffffff!important;border-color:rgba(47,38,32,.18)!important;color:#2f2620!important;border-radius:0}.layadmin-user-login-box .layui-input:focus{border-color:#b3541e!important}.layadmin-user-login-icon{color:#7b6a5b}.layadmin-user-login-box .layui-btn{background:#b3541e!important;color:#fff9f0!important;border-radius:0;font-weight:600;border:none}.layadmin-user-login-box .layui-btn:hover{filter:brightness(1.08)}.layadmin-user-login-other,.layadmin-user-login-other label{color:#7b6a5b}.layadmin-user-login-other a{color:#b3541e!important}.layadmin-user-login-footer p,.layadmin-user-login-footer a{color:#7b6a5b!important}.layadmin-user-login-main{border-radius:0;box-shadow:none;border:none;border-top:6px double #2f2620}.layadmin-user-login-main:before{display:none}.layadmin-user-login-header h2{letter-spacing:1px}.layadmin-user-login-box .layui-btn{letter-spacing:2px}",
            ],
            'neon' => [
                'name' => "暗黑霓虹", 'desc' => "夜店海报：全屏霓虹大字 + 跑马灯 + 竖排边栏导航",
                'c1' => '#05010f', 'c2' => '#00e5ff', 'c3' => '#ff2e97', 'mock' => 'glow',
                'accent' => '#00e5ff', 'ink' => '#e8f6ff', 'card' => 'rgba(14,8,34,.72)',
                'auth' => ".layadmin-user-login{background:#05010f;background-image:linear-gradient(rgba(0,229,255,.09) 1px,transparent 1px),linear-gradient(90deg,rgba(0,229,255,.09) 1px,transparent 1px);background-size:46px 46px!important;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif}.layadmin-user-login-main{background:rgba(14,8,34,.72)!important;border:1px solid rgba(0,229,255,.22);border-radius:14px;box-shadow:0 18px 46px rgba(0,229,255,.18);padding:30px 32px 24px}.layadmin-user-login-main:before{content:\"\";display:block;height:6px;margin:-30px -32px 22px;border-radius:14px 14px 0 0;background:linear-gradient(90deg,#00e5ff,#ff2e97)}.layadmin-user-login-header h2{color:#e8f6ff!important;font-weight:800;font-size:24px}.layadmin-user-login-header p{color:#8fa8c8!important}.layadmin-user-login-box .layui-input{background:rgba(255,255,255,.06)!important;border-color:rgba(0,229,255,.22)!important;color:#e8f6ff!important;border-radius:10px}.layadmin-user-login-box .layui-input:focus{border-color:#00e5ff!important}.layadmin-user-login-icon{color:#8fa8c8}.layadmin-user-login-box .layui-btn{background:#00e5ff!important;color:#04101a!important;border-radius:10px;font-weight:600;border:none}.layadmin-user-login-box .layui-btn:hover{filter:brightness(1.08)}.layadmin-user-login-other,.layadmin-user-login-other label{color:#8fa8c8}.layadmin-user-login-other a{color:#00e5ff!important}.layadmin-user-login-footer p,.layadmin-user-login-footer a{color:#8fa8c8!important}.layadmin-user-login-main{box-shadow:0 0 46px rgba(0,229,255,.18)}.layadmin-user-login-header h2{text-shadow:0 0 22px rgba(0,229,255,.55)}.layadmin-user-login-box .layui-btn{background:linear-gradient(135deg,#00e5ff,#ff2e97)!important;color:#04101a!important;font-weight:800}.layadmin-user-login-main:before{background:linear-gradient(90deg,#00e5ff,#ff2e97)}",
            ],
            'terminal' => [
                'name' => "赛博终端", 'desc' => "一个终端窗口：敲命令看功能，黑底绿字带扫描线",
                'c1' => '#000000', 'c2' => '#39ff14', 'c3' => '#0aff9d', 'mock' => 'term',
                'accent' => '#39ff14', 'ink' => '#c9ffd8', 'card' => 'rgba(6,26,16,.92)',
                'auth' => ".layadmin-user-login{background:#020b06;background-image:repeating-linear-gradient(to bottom,rgba(57,255,20,.06) 0 1px,transparent 1px 3px)!important;font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,'Courier New',monospace}.layadmin-user-login-main{background:rgba(6,26,16,.92)!important;border:1px solid rgba(57,255,20,.3);border-radius:6px;box-shadow:0 0 0 1px rgba(57,255,20,.18);padding:30px 32px 24px}.layadmin-user-login-main:before{content:\"\";display:block;height:6px;margin:-30px -32px 22px;border-radius:6px 6px 0 0;background:linear-gradient(90deg,#39ff14,#0aff9d)}.layadmin-user-login-header h2{color:#c9ffd8!important;font-weight:800;font-size:24px}.layadmin-user-login-header p{color:#6fbf8a!important}.layadmin-user-login-box .layui-input{background:rgba(57,255,20,.06)!important;border-color:rgba(57,255,20,.3)!important;color:#c9ffd8!important;border-radius:4px}.layadmin-user-login-box .layui-input:focus{border-color:#39ff14!important}.layadmin-user-login-icon{color:#6fbf8a}.layadmin-user-login-box .layui-btn{background:#39ff14!important;color:#02140a!important;border-radius:4px;font-weight:600;border:none}.layadmin-user-login-box .layui-btn:hover{filter:brightness(1.08)}.layadmin-user-login-other,.layadmin-user-login-other label{color:#6fbf8a}.layadmin-user-login-other a{color:#39ff14!important}.layadmin-user-login-footer p,.layadmin-user-login-footer a{color:#6fbf8a!important}.layadmin-user-login-main{border-style:dashed}.layadmin-user-login-header h2:before{content:'$ ';color:#39ff14}.layadmin-user-login-box .layui-btn{text-transform:uppercase;letter-spacing:1.5px}.layadmin-user-login:after{content:'';position:absolute;inset:0;pointer-events:none;background:repeating-linear-gradient(to bottom,rgba(57,255,20,.05) 0 1px,transparent 1px 3px)}",
            ],
            'glass' => [
                'name' => "玻璃拟态", 'desc' => "磨砂玻璃面板 + 底部悬浮 Dock，背景光斑缓缓流动",
                'c1' => '#7c5cff', 'c2' => '#22d3ee', 'c3' => '#ff7ac6', 'mock' => 'glass',
                'accent' => '#7c5cff', 'ink' => '#101a35', 'card' => 'rgba(255,255,255,.6)',
                'auth' => ".layadmin-user-login{background:linear-gradient(135deg,#eef2ff,#e4e9ff 45%,#e8f8ff)!important;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif}.layadmin-user-login-main{background:rgba(255,255,255,.6)!important;border:1px solid rgba(255,255,255,.75);border-radius:26px;box-shadow:0 22px 50px rgba(60,70,140,.18);padding:30px 32px 24px}.layadmin-user-login-main:before{content:\"\";display:block;height:6px;margin:-30px -32px 22px;border-radius:26px 26px 0 0;background:linear-gradient(90deg,#7c5cff,#22d3ee)}.layadmin-user-login-header h2{color:#101a35!important;font-weight:800;font-size:24px}.layadmin-user-login-header p{color:#5a6785!important}.layadmin-user-login-box .layui-input{background:#ffffff!important;border-color:rgba(255,255,255,.75)!important;color:#101a35!important;border-radius:18px}.layadmin-user-login-box .layui-input:focus{border-color:#7c5cff!important}.layadmin-user-login-icon{color:#5a6785}.layadmin-user-login-box .layui-btn{background:#7c5cff!important;color:#ffffff!important;border-radius:18px;font-weight:600;border:none}.layadmin-user-login-box .layui-btn:hover{filter:brightness(1.08)}.layadmin-user-login-other,.layadmin-user-login-other label{color:#5a6785}.layadmin-user-login-other a{color:#7c5cff!important}.layadmin-user-login-footer p,.layadmin-user-login-footer a{color:#5a6785!important}.layadmin-user-login-main{background:rgba(255,255,255,.62)!important;-webkit-backdrop-filter:blur(18px);backdrop-filter:blur(18px);box-shadow:0 24px 60px rgba(60,70,140,.2)}.layadmin-user-login-box .layui-btn{background:linear-gradient(135deg,#7c5cff,#22d3ee)!important}",
            ],
            'bento' => [
                'name' => "便当网格", 'desc' => "单屏便当网格：所有内容都是大小不一的格子",
                'c1' => '#fff7ed', 'c2' => '#ffb703', 'c3' => '#2ec4b6', 'mock' => 'bento',
                'accent' => '#ff8a3d', 'ink' => '#2b2118', 'card' => '#ffffff',
                'auth' => ".layadmin-user-login{background:#fff8f1;background-image:radial-gradient(rgba(255,138,61,.18) 1.5px,transparent 1.5px);background-size:28px 28px!important;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif}.layadmin-user-login-main{background:#ffffff!important;border:1px solid rgba(43,33,24,.09);border-radius:22px;box-shadow:0 20px 44px rgba(255,138,61,.2);padding:30px 32px 24px}.layadmin-user-login-main:before{content:\"\";display:block;height:6px;margin:-30px -32px 22px;border-radius:22px 22px 0 0;background:linear-gradient(90deg,#ff8a3d,#2ec4b6)}.layadmin-user-login-header h2{color:#2b2118!important;font-weight:800;font-size:24px}.layadmin-user-login-header p{color:#8a7a6b!important}.layadmin-user-login-box .layui-input{background:#ffffff!important;border-color:rgba(43,33,24,.09)!important;color:#2b2118!important;border-radius:14px}.layadmin-user-login-box .layui-input:focus{border-color:#ff8a3d!important}.layadmin-user-login-icon{color:#8a7a6b}.layadmin-user-login-box .layui-btn{background:#ff8a3d!important;color:#ffffff!important;border-radius:14px;font-weight:600;border:none}.layadmin-user-login-box .layui-btn:hover{filter:brightness(1.08)}.layadmin-user-login-other,.layadmin-user-login-other label{color:#8a7a6b}.layadmin-user-login-other a{color:#ff8a3d!important}.layadmin-user-login-footer p,.layadmin-user-login-footer a{color:#8a7a6b!important}.layadmin-user-login-main{border-radius:26px}.layadmin-user-login-main:before{border-radius:26px 26px 0 0}.layadmin-user-login-box .layui-btn{border-radius:14px}",
            ],
            'classic' => [
                'name' => "经典原版", 'desc' => "原来的蓝色渐变落地页（保留备用）",
                'c1' => '#6dd5ed', 'c2' => '#1a73e8', 'c3' => '#00008b', 'mock' => 'bars',
                'accent' => '#1a73e8', 'ink' => '#333333', 'card' => '#ffffff',
                'auth' => ".layadmin-user-login{background:linear-gradient(135deg,#6dd5ed 0%,#1a73e8 55%,#00008b 100%)!important;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','PingFang SC','Hiragino Sans GB','Microsoft YaHei',sans-serif}.layadmin-user-login-main{background:#ffffff!important;border:1px solid rgba(26,115,232,.18);border-radius:12px;box-shadow:0 24px 60px rgba(26,115,232,.22);padding:30px 32px 24px}.layadmin-user-login-main:before{content:\"\";display:block;height:6px;margin:-30px -32px 22px;border-radius:12px 12px 0 0;background:linear-gradient(90deg,#1a73e8,#00008b)}.layadmin-user-login-header h2{color:#333333!important;font-weight:800;font-size:24px}.layadmin-user-login-header p{color:#7a8699!important}.layadmin-user-login-box .layui-input{background:rgba(255,255,255,.95)!important;border-color:rgba(26,115,232,.18)!important;color:#333333!important;border-radius:6px}.layadmin-user-login-box .layui-input:focus{border-color:#1a73e8!important}.layadmin-user-login-icon{color:#7a8699}.layadmin-user-login-box .layui-btn{background:#1a73e8!important;color:#ffffff!important;border-radius:6px;font-weight:600;border:none}.layadmin-user-login-box .layui-btn:hover{filter:brightness(1.08)}.layadmin-user-login-other,.layadmin-user-login-other label{color:#7a8699}.layadmin-user-login-other a{color:#1a73e8!important}.layadmin-user-login-footer p,.layadmin-user-login-footer a{color:#7a8699!important}.layadmin-user-login-main{border-radius:12px}.layadmin-user-login-header h2{color:#1a73e8}",
            ],
        ];
    }

    // 登录 / 注册 / 找回密码页是否跟着首页主题换配色（后台「主题设置」里可关）
    function theme_auth_style()
    {
        if (config('web.auth_theme_follow') === '0') {
            return '';
        }
        return theme_auth_css(home_theme_key());
    }

    // 按主题 key 生成登录页配色（登录、注册、找回密码共用）
    function theme_auth_css($key)
    {
        $themes = home_themes();
        if (!isset($themes[$key]) || empty($themes[$key]['auth'])) {
            $key = 'minimal';
        }
        return '<style id="lh-auth-theme">/* 登录页配色跟随首页主题：' . $themes[$key]['name'] . ' */'
            . $themes[$key]['auth'] . '</style>';
    }


// 当前首页主题（后台没设置或设置成不存在的值就回到默认主题）
function home_theme_key()
{
    $key = (string) config('web.home_theme');
    if ($key === '' || !array_key_exists($key, home_themes())) {
        $key = 'minimal';
    }
    return $key;
}

function get_ip_city($ip)
{
    $ip = empty($ip) ? request()->ip() : $ip;
    // 优化：同一个 IP 24 小时内只查一次（登录/注册都会调用它，
    // 而查询要访问外部接口，实测每次约 1.4 秒，是登录变慢的主因）
    $ck = 'ipcity_' . md5($ip);
    $cached = \think\facade\Cache::get($ck);
    if ($cached !== null && $cached !== '') {
        return $cached;
    }
    $url = 'https://api.cenguigui.cn/api/UserInfo/bt.php?ip=' . $ip;
    // 上游不稳定时降级，避免 file_get_contents 遇 5xx 抛异常导致登录 500
    $city = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $city = curl_exec($ch);
        curl_close($ch);
    }
    $city = $city ? json_decode($city, true) : null;
    if (isset($city['data']['location'])) {
        $result = $city['data']['location'];
    } else {
        $result = '未知地址';
    }
    \think\facade\Cache::set($ck, $result, 86400);
    return $result;
}
// 域名授权功能开关（在「更多设置 → 域名授权」里控制）
// 关闭后：播放器编辑页不再显示「域名授权」标签（已授权域名列表、新增授权表单），
//         授权相关接口也不再接受操作。
function domain_auth_enabled()
{
    return config('web.auth_domain_enable') !== '0';
}

// 音乐授权开关（「更多设置 → 音乐授权」里控制）
// 关闭 = 跳过授权验证，取歌直接走音乐接口；开启 = 用授权QQ + skey 向上游校验。
function auth_qq_enabled()
{
    return config('web.auth_qq_enable') === '1';
}

// 授权QQ 与 skey：返回 [qq, skey]，没配置时返回空串表示「没填」
function auth_qq_values()
{
    $qq = trim((string) config('web.auth_qq'));
    $skey = trim((string) config('web.auth_skey'));
    return [$qq, $skey];
}

// 是否要真的去校验：开关开着 + 两个值都填了 + skey 不是占位符
function auth_qq_ready()
{
    if (!auth_qq_enabled()) {
        return false;
    }
    list($qq, $skey) = auth_qq_values();
    if ($qq === '' || $skey === '') {
        return false;
    }
    if (strpos($skey, '*******') !== false) {
        return false;   // 还是模板里的占位符，等于没填
    }
    return true;
}

function get_domain()
{
    $siteurl = ($_SERVER['SERVER_PORT'] == '443' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/';
    return $siteurl;
}
function authcode($string, $operation = '', $key = '', $expiry = 0)
{
    $ckey_length = 4;
    $key = md5($key ? $key : 'zyzs');
    $keya = md5(substr($key, 0, 16));
    $keyb = md5(substr($key, 16, 16));
    $keyc = $ckey_length ? ($operation == 'DECODE' ? substr($string, 0, $ckey_length) : substr(md5(microtime()), $ckey_length * -1)) : '';
    $cryptkey = $keya . md5($keya . $keyc);
    $key_length = strlen($cryptkey);
    $string = $operation == 'DECODE' ? base64_decode(substr($string, $ckey_length)) : sprintf('%010d', $expiry ? $expiry + time() : 0) . substr(md5($string . $keyb), 0, 16) . $string;
    $string_length = strlen($string);
    $result = '';
    $box = range(0, 255);
    $rndkey = [];
    for ($i = 0; $i <= 255; ++$i) {
        $rndkey[$i] = ord($cryptkey[$i % $key_length]);
    }
    $j = $i = 0;
    while ($i < 256) {
        $j = ($j + $box[$i] + $rndkey[$i]) % 256;
        $tmp = $box[$i];
        $box[$i] = $box[$j];
        $box[$j] = $tmp;
        ++$i;
    }
    $a = $j = $i = 0;
    while ($i < $string_length) {
        $a = ($a + 1) % 256;
        $j = ($j + $box[$a]) % 256;
        $tmp = $box[$a];
        $box[$a] = $box[$j];
        $box[$j] = $tmp;
        $result .= chr(ord($string[$i]) ^ $box[($box[$a] + $box[$j]) % 256]);
        ++$i;
    }
    if ($operation == 'DECODE') {
        $timestamp = substr($result, 0, 10);
        if (is_numeric($timestamp) && ($timestamp == 0 || $timestamp - time() > 0) && substr($result, 10, 16) == substr(md5(substr($result, 26) . $keyb), 0, 16)) {
            return substr($result, 26);
        }
        return '';
    }
    return $keyc . str_replace('=', '', base64_encode($result));
}
function drawings_js($money, $drawings)
{
    $drawings = $money * $drawings;
    return $drawings;
}
function getFilesize($filesize)
{
    if ($filesize >= 1048576) {
        $filesize = round($filesize / 1048576 * 100) / 100 . 'GB';
    } elseif ($filesize >= 1024) {
        $filesize = round($filesize / 1024 * 100) / 100 . 'MB';
    } else {
        $filesize = $filesize . 'KB';
    }
    return $filesize;
}
function my_sort($arrays, $sort_key, $sort_order = SORT_DESC, $sort_type = SORT_NUMERIC)
{
    if (is_array($arrays)) {
        foreach ($arrays as $array) {
            if (is_array($array)) {
                $key_arrays[] = $array[$sort_key];
                continue;
            }
            return false;
        }
        array_multisort($key_arrays, $sort_order, $sort_type, $arrays);
        return $arrays;
    }
    return false;
}
function hideStr($string, $bengin = 0, $len = 4, $type = 0, $glue = '@')
{
    if (empty($string)) {
        return false;
    }
    $array = [];
    if ($type == 0 || $type == 1 || $type == 4) {
        $strlen = $length = mb_strlen($string);
        while ($strlen) {
            $array[] = mb_substr($string, 0, 1, 'utf8');
            $string = mb_substr($string, 1, $strlen, 'utf8');
            $strlen = mb_strlen($string);
        }
    }
    switch ($type) {
        case 0:
            for ($i = $bengin; $i < $bengin + $len; ++$i) {
                if (isset($array[$i])) {
                    $array[$i] = '*';
                }
            }
            $string = implode('', $array);
            break;
        case 1:
            $array = array_reverse($array);
            for ($i = $bengin; $i < $bengin + $len; ++$i) {
                if (isset($array[$i])) {
                    $array[$i] = '*';
                }
            }
            $string = implode('', array_reverse($array));
            break;
        case 2:
            $array = explode($glue, $string);
            $array[0] = hideStr($array[0], $bengin, $len, 1);
            $string = implode($glue, $array);
            break;
        case 3:
            $array = explode($glue, $string);
            $array[1] = hideStr($array[1], $bengin, $len, 0);
            $string = implode($glue, $array);
            break;
        case 4:
            $left = $bengin;
            $right = $len;
            $tem = [];
            for ($i = 0; $i < $length - $right; ++$i) {
                if (isset($array[$i])) {
                    $tem[] = $left <= $i ? '*' : $array[$i];
                }
            }
            $array = array_chunk(array_reverse($array), $right);
            $array = array_reverse($array[0]);
            for ($i = 0; $i < $right; ++$i) {
                $tem[] = $array[$i];
            }
            $string = implode('', $tem);
            break;
    }
    return $string;
}
function whits($num)
{
    return $num < 10000 ? $num : sprintf('%.1f', $num / 10000) . '万';
}
function time_tran($the_time)
{
    $now_time = date('Y-m-d H:i:s', time());
    $now_time = strtotime($now_time);
    $show_time = strtotime($the_time);
    $dur = $now_time - $show_time;
    if ($dur < 0) {
        return $the_time;
    }
    if ($dur < 60) {
        return $dur . '秒前';
    }
    if ($dur < 3600) {
        return floor($dur / 60) . '分钟前';
    }
    if ($dur < 86400) {
        return floor($dur / 3600) . '小时前';
    }
    if ($dur < 2592000) {
        return floor($dur / 86400) . '天前';
    }
    if ($dur < 31104000) {
        return floor($dur / 2592000) . '月前';
    }
    if ($dur < 3110400000.0) {
        return floor($dur / 31104000) . '年前';
    }
    return $the_time;
}
// 判断外链是否为安全的公网 http/https 地址（防 SSRF / 本地文件读取）
function is_safe_remote_url($url)
{
    if (!is_string($url) || trim($url) === '') {
        return false;
    }
    $url = str_replace(' ', '', trim($url));
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    $scheme = strtolower($parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $host = strtolower($parts['host']);
    if ($host === 'localhost' || substr($host, -6) === '.local' || strpos($host, '.') === false) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    $ip = gethostbyname($host);
    if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    return true;
}

function mainColor($image)
{
    $imageInfo = getimagesize($image);
    if (!$imageInfo || empty($imageInfo[2])) {
        // 修复：图片读取失败时直接返回默认色，避免后续报错
        return [0, 0, 0];
    }
    $imgType = strtolower(substr(image_type_to_extension($imageInfo[2]), 1));
    $imageFun = 'imagecreatefrom' . ($imgType == 'jpg' ? 'jpeg' : $imgType);
    $i = $imageFun($image);
    if (!$i) {
        return [0, 0, 0];
    }
    $rColorNum = $gColorNum = $bColorNum = $total = 0;
    for ($x = 50; $x < imagesx($i) - 50; ++$x) {
        for ($y = 50; $y < imagesy($i) - 50; ++$y) {
            $rgb = imagecolorat($i, $x, $y);
            $r = $rgb >> 16 & 255;
            $g = $rgb >> 8 & 255;
            $b = $rgb & 255;
            $rColorNum += $r;
            $gColorNum += $g;
            $bColorNum += $b;
            ++$total;
        }
    }
    if ($total <= 0) {
        // 修复：小图/取色失败时 $total 为 0，原来会抛 DivisionByZeroError
        return [0, 0, 0];
    }
    return [round($rColorNum / $total), round($gColorNum / $total), round($bColorNum / $total)];
}
function is_peie_num($int)
{
    switch ($int) {
        case 1:
            $num = 1;
            break;
        case 2:
            $num = 3;
            break;
        case 3:
            $num = 5;
            break;
        case 4:
            $num = 10;
            break;
    }
    return $num;
}
function get_url_ico($url)
{
    if (strpos($url, 'http') !== 0) {
        $url = 'http://' . $url;
    }
    $response = \think\facade\Request::get($url);
    $html = $response->getContent();
    $regex = '/<link\s+rel="(?:icon|shortcut icon)"\s+href="([^"\s]+)"/i';
    if (preg_match($regex, $html, $matches)) {
        $faviconUrl = $matches[1];
        if (strpos($faviconUrl, 'http') !== 0) {
            $faviconUrl = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . $faviconUrl;
        }
        if (\think\facade\Request::get($faviconUrl)->isSuccess()) {
            return $faviconUrl;
        }
    }
    return '/favicon.ico';
}
function url_exists($url)
{
    $head = @get_headers($url);
    return is_array($head) ? true : false;
}
function remote_file_exists($url)
{
    $executeTime = ini_get('max_execution_time');
    ini_set('max_execution_time', 0);
    $headers = @get_headers($url);
    ini_set('max_execution_time', $executeTime);
    if ($headers) {
        $head = explode(' ', $headers[0]);
        if (!empty($head[1]) && (int)$head[1] < 400) {
            return true;
        }
    }
    return false;
}
function Pay($orderid, $name, $price, $type, $shop, $shopid)
{
    $epay_config = ['partner' => \think\facade\Config::get('web.epay_id'), 'key' => \think\facade\Config::get('web.epay_key'), 'sign_type' => strtoupper('MD5'), 'input_charset' => strtolower('utf-8'), 'transport' => 'http', 'apiurl' => \think\facade\Config::get('web.epay_url')];
    $parameter = ['pid' => trim($epay_config['partner']), 'type' => $type, 'notify_url' => 'http://' . $_SERVER['HTTP_HOST'] . url('Epay/' . $shop . '_Notify'), 'return_url' => 'http://' . $_SERVER['HTTP_HOST'] . url('Epay/' . $shop . '_Return'), 'out_trade_no' => $orderid, 'name' => $name, 'money' => $price, 'shop' => $shop, 'shopid' => $shopid, 'sitename' => \think\facade\Config::get('web.webname')];
    $alipaySubmit = new AlipaySubmit($epay_config);
    $html_text = $alipaySubmit->buildRequestForm($parameter, 'get');
    return $html_text;
}
require_once __DIR__ . '/../extend/Epay/epay_submit.class.php';
define('music_api', 'https://musicapi.cenguigui.cn');

// 会话加密密钥：只存在于服务端，绝不使用客户端 Cookie 作为密钥
// （修复：pskey 可用攻击者自选的 skey 伪造，从而冒充任意用户）
!defined('AUTH_KEY') && define('AUTH_KEY', \think\facade\Env::get('AUTH_KEY', 'LihuaSessionKey_7f3c9a1e5b8d2460a1c4e7f90b2d6358019a4c8e2f7b3d5160a9c4e8'));

// 音乐接口地址：后台「更多设置 → 音乐授权」里填的值优先，留空就用代码里的默认值
function music_api_url()
{
    $custom = trim((string) \think\facade\Config::get('web.music_api'));
    if ($custom !== '' && stripos($custom, 'http') === 0) {
        return rtrim($custom, '/');
    }
    return music_api;
}

// 音乐接口的域名（报错信息里给用户看）
function music_api_host($url = null)
{
    $url = $url === null ? music_api_url() : $url;
    $host = parse_url($url, PHP_URL_HOST);
    return $host ? $host : $url;
}

// 音乐接口格式：auto=先用自己的接口、取不到再用内置免费接口；default=只用自己填的；vkeys=只用内置免费接口
function music_api_mode()
{
    $mode = strtolower(trim((string) \think\facade\Config::get('web.music_api_mode')));
    return in_array($mode, ['default', 'vkeys'], true) ? $mode : 'auto';
}

// 内置免费接口（api.vkeys.cn，可用 music_vkeys_api 覆盖）：目前只有 QQ 音乐能按 mid 精确取到播放地址
function music_vkeys_resolve($type, $id)
{
    $type = strtolower(trim((string) $type));
    if (!in_array($type, ['qq', 'tencent', 'qqmusic'], true)) {
        return false;
    }
    $id = trim((string) $id);
    if ($id === '') {
        return false;
    }
    $base = trim((string) \think\facade\Config::get('web.music_vkeys_api'));
    if ($base === '' || stripos($base, 'http') !== 0) {
        $base = 'https://api.vkeys.cn';
    }
    $json = send_get(rtrim($base, '/') . '/v2/music/tencent?mid=' . urlencode($id));
    $row = json_decode((string) $json, true);
    if (!is_array($row)) {
        return false;
    }
    $data = $row['data'] ?? null;
    if (is_array($data) && isset($data[0])) {
        $data = $data[0];
    }
    if (!is_array($data) || empty($data['url']) || !is_safe_remote_url($data['url'])) {
        return false;
    }
    return [
        'url' => $data['url'],
        'title' => $data['song'] ?? '',
        'author' => $data['singer'] ?? '',
        'album' => $data['album'] ?? '',
        'pic' => $data['cover'] ?? '',
    ];
}
