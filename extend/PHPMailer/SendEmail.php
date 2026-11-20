<?php

namespace PHPMailer;

class SendEmail
{
    private static $demo = 'https://music.ovoeo.cn'; //第你的网址，只用于用户收到邮箱后访问主页
    public static $Host = 'smtp.qq.com'; //smtp服务器
    private static $From = 'cenguigui@qq.com'; //发送者的邮件地址
    private static $FromName = '笒鬼鬼音乐播放器'; //发送邮件的用户昵称
    private static $Username = 'cenguigui@qq.com'; //登录到邮箱的用户名
    private static $Password = '********'; //第三方登录的授权码，在邮箱里面设置

    /**
     * @desc 发送普通邮件
     * @param $title 邮件标题
     * @param $message 邮件正文
     * @param $emailAddress 邮件地址
     * @return bool|string 返回是否发送成功
     */
    public static function SendEmail($title = '测试邮件', $message = '你好,本邮件由笒鬼鬼音乐播放器发出', $emailAddress = 'cenguigui@qq.com')
    {
        // 优先使用后台「更多设置 → 邮箱设置」里的配置，未填写时回退到本文件里的默认值
        $cfg = function ($key, $default = '') {
            $v = \think\facade\Config::get('web.' . $key, '');
            return ($v === '' || $v === null) ? $default : $v;
        };
        if ((string) $cfg('mail_enable', '1') === '0') {
            return '邮箱功能未开启（请到 后台 → 更多设置 → 邮箱设置 中开启）';
        }
        $host     = (string) $cfg('smtp_host', self::$Host);
        $username = (string) $cfg('smtp_user', self::$Username);
        $password = (string) $cfg('smtp_pass', self::$Password);
        $from     = (string) $cfg('mail_from', $username);
        $fromName = (string) $cfg('mail_from_name', self::$FromName);
        $secure   = strtolower(trim((string) $cfg('smtp_secure', 'ssl')));
        if ($secure === 'none') {
            $secure = '';
        }
        $defPort  = ($secure === 'tls') ? 587 : (($secure === 'ssl') ? 465 : 25);
        $port     = (int) $cfg('smtp_port', $defPort);
        if ($port <= 0) {
            $port = $defPort;
        }
        $demo = (string) $cfg('mail_demo', '');
        if ($demo === '') {
            $demo = function_exists('get_domain') ? get_domain() : self::$demo;
        }
        $mail = new PHPMailer();
        // 3. 设置属性，告诉我们的服务器，谁跟谁发送邮件
        $mail->IsSMTP();            // 告诉服务器使用smtp协议发送
        $mail->SMTPAuth = true;        // 开启SMTP授权
        $mail->SMTPSecure = $secure;    // ssl / tls / 空
        $mail->Port = $port;            // 端口
        $mail->Host = $host;            // smtp 服务器
        $mail->Timeout = 15;            // 修复：防止 SMTP 不可达时把请求挂住（默认是 300 秒）
        $mail->From = $from;            // 发送者的邮件地址
        $mail->FromName = $fromName;    // 发送邮件的用户昵称
        $mail->Username = $username;    // 登录到邮箱的用户名
        $mail->Password = $password;    // 第三方登录的授权码

        // 编辑发送的邮件内容
        $mail->IsHTML(true);            // 发送的内容使用html编写
        $mail->CharSet = 'utf-8';        // 设置发送内容的编码
        $mail->Subject = $title; // 设置邮件的标题
        // 美化邮件的HTML内容
        $htmlContent = '
            <html>
            <head>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        background-color: #f4f4f4;
                        color: #333;
                        margin: 0;
                        padding: 0;
                    }
                    .email-container {
                        width: 100%;
                        max-width: 600px;
                        margin: 0 auto;
                        background-color: #ffffff;
                        border: 1px solid #ddd;
                        padding: 20px;
                        box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
                    }
                    .email-header {
                        background-color: #4CAF50;
                        color: white;
                        padding: 10px;
                        text-align: center;
                        font-size: 24px;
                        border-radius: 5px 5px 0 0;
                    }
                    .email-body {
                        padding: 20px;
                        font-size: 16px;
                        line-height: 1.6;
                        text-align: left;
                    }
                    .email-footer {
                        text-align: center;
                        padding: 10px;
                        font-size: 12px;
                        background-color: #f1f1f1;
                        border-radius: 0 0 5px 5px;
                        color: #777;
                    }
                    .email-footer a {
                        color: #4CAF50;
                        text-decoration: none;
                    }
                </style>
            </head>
            <body>
                <div class="email-container">
                    <div class="email-header">
                        ' . htmlspecialchars($title) . '
                    </div>
                    <div class="email-body">
                        <p>' . nl2br(htmlspecialchars($message)) . '</p>
                    </div>
                    <div class="email-footer">
                        <p>此邮件由' . htmlspecialchars($from) . '自动发送。</p>
                        <p><a href="' . htmlspecialchars($demo) . '">访问我们的主页</a></p>
                    </div>
                </div>
            </body>
            </html>';
        // 将美化后的HTML内容设置为邮件的主体
        $mail->MsgHTML($htmlContent);
        // 添加收件人
        $mail->AddAddress($emailAddress);    // 收件人的邮件地址
        // 调用send方法，执行发送
        $result = $mail->Send();
        if ($result) {
            return true;
        } else {
            return $mail->ErrorInfo;
        }
    }
}
