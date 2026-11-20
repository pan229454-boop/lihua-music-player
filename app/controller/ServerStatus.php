<?php
namespace app\controller;

use think\facade\View;
use app\model\Users;

/**
 * 后台「服务器状态」用的接口
 *
 * 说明：作者视图 view/admin/serverstate.html 里请求的是 /serverStatus/getStatus
 *      与 /serverStatus/stopProcess，但控制器在这个版本里缺失，页面只会提示
 *      「获取服务器状态失败」，菜单项也被注释掉了。这里把这套接口补齐：
 *        - getStatus   ：读 /proc 输出进程列表（PID / 用户 / CPU% / 内存% / 命令）
 *        - stopProcess ：向指定 PID 发停止信号，关键进程与容器基础进程直接拒绝
 *      容器里 /proc/stat 常常不可读，所以 CPU% 用两次采样的 utime+stime 增量估算。
 */
class ServerStatus extends Admin
{
    // 这些关键字的进程不允许通过后台停止（Web 服务器自身、数据库、系统与容器基础进程）
    private $protectWords = [
        'php', 'php-fpm', 'mariadb', 'mysqld', 'mysql', 'nginx', 'apache', 'httpd',
        'sshd', 'systemd', 'init', 'proot', 'bash', 'sh', 'su', 'adb', 'node',
        'dsh', 'containerd', 'dockerd', 'cron',
    ];

    public function getStatus()
    {
        if (!$this->checkAdmin()) {
            return json(['status' => 'error', 'message' => '你没有权限使用该功能']);
        }

        $mem_total = 0;
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo && preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
            $mem_total = intval($m[1]);
        }

        // 进程 CPU 占用：两次采样求 utime + stime 的增量（0.3 秒窗口）
        $first = $this->snapshot();
        usleep(300000);
        $second = $this->snapshot();

        $list = [];
        $isProtected = 0;
        foreach ($second as $pid => $info) {
            $prev = isset($first[$pid]) ? $first[$pid] : $info;
            $ticks = max($info['ticks'] - $prev['ticks'], 0);
            $cpu = round($ticks / 100 / 0.3 * 100, 2); // 每秒 100 个时钟滴答，单核满值 100%
            if ($cpu > 100) {
                $cpu = 100;
            }
            $protect = $this->isProtected($pid, $info['command']);
            if ($protect) {
                $isProtected++;
            }
            $list[] = [
                'pid' => $pid,
                'user' => $info['user'],
                'cpu' => $cpu,
                'mem' => $mem_total > 0 ? round($info['rss_kb'] / $mem_total * 100, 2) : 0,
                'command' => $info['command'],
                'rss' => $this->toSize($info['rss_kb'] * 1024),
                'cputime' => $this->formatTicks($info['ticks']),
                'protect' => $protect,
                'memsort' => $info['rss_kb'],
            ];
        }
        usort($list, function ($a, $b) {
            return $b['memsort'] - $a['memsort'];
        });
        foreach ($list as $k => $one) {
            unset($list[$k]['memsort']);
        }

        return json([
            'status' => 'success',
            'data' => $list,
            'count' => count($list),
            'protected' => $isProtected,
            'memTotal' => $mem_total > 0 ? $this->toSize($mem_total * 1024) : 'N/A',
            'php' => PHP_VERSION,
            'os' => php_uname('s') . ' ' . php_uname('r'),
            'tip' => '容器环境里 /proc/stat 不可读时，CPU% 由前后两次采样的差值估算（0.3 秒窗口），仅供参考；带「受保护」标记的进程不允许在这里停止。',
        ]);
    }

    public function stopProcess()
    {
        if (!$this->checkAdmin()) {
            return json(['status' => 'error', 'message' => '你没有权限使用该功能']);
        }

        $pid = intval(input('post.pid', 0));
        if ($pid <= 1) {
            return json(['status' => 'error', 'message' => 'PID 不合法']);
        }
        if (!is_dir('/proc/' . $pid)) {
            return json(['status' => 'error', 'message' => '进程 ' . $pid . ' 不存在或已结束']);
        }
        if ($pid === getmypid() || (function_exists('posix_getppid') && $pid === posix_getppid())) {
            return json(['status' => 'error', 'message' => '不能停止当前 Web 进程或它的父进程']);
        }

        $snap = $this->snapshot();
        $cmd = isset($snap[$pid]) ? $snap[$pid]['command'] : '';
        $hit = $this->matchedProtect($cmd);
        if ($hit !== null) {
            return json(['status' => 'error', 'message' => '出于安全考虑，关键进程（含 ' . $hit . '）不允许在这里停止']);
        }

        $ok = function_exists('posix_kill') ? @posix_kill($pid, 15) : false;
        if (!$ok && function_exists('exec')) {
            $out = [];
            $code = 1;
            @exec('kill -15 ' . intval($pid) . ' 2>/dev/null', $out, $code);
            $ok = ($code === 0);
        }
        if ($ok) {
            return json(['status' => 'success', 'message' => '已向进程 ' . $pid . ' 发送停止信号']);
        }
        return json(['status' => 'error', 'message' => '停止失败，可能没有权限']);
    }

    // 未登录会走系统错误页（与其它后台接口一致），非管理员返回 false
    private function checkAdmin()
    {
        $this->checkLogin();
        $userInfo = Users::getLoginUser();
        if (!$userInfo) {
            return false;
        }
        // getLoginUser() 返回的是 model（支持数组下标访问），power=0 才是管理员
        return intval($userInfo['power']) === 0;
    }

    private function isProtected($pid, $command)
    {
        if ($pid <= 1) {
            return true;
        }
        if ($pid === getmypid()) {
            return true;
        }
        if (function_exists('posix_getppid') && $pid === posix_getppid()) {
            return true;
        }
        return $this->matchedProtect($command) !== null;
    }

    private function matchedProtect($command)
    {
        $cmd = (string) $command;
        foreach ($this->protectWords as $word) {
            if (stripos($cmd, $word) !== false) {
                return $word;
            }
        }
        return null;
    }

    // 采集一次所有进程的 CPU 时间、内存、用户与命令行
    private function snapshot()
    {
        $list = [];
        foreach ((array) glob('/proc/[0-9]*') as $dir) {
            $pid = intval(basename($dir));
            if ($pid <= 0) {
                continue;
            }
            $stat = @file_get_contents($dir . '/stat');
            if (!$stat) {
                continue;
            }
            $pos = strrpos($stat, ')');
            if ($pos === false) {
                continue;
            }
            $comm = trim(substr($stat, 1, $pos - 1));
            $fields = preg_split('/\s+/', trim(substr($stat, $pos + 1)));
            if (count($fields) < 22) {
                continue;
            }
            $ticks = intval($fields[11]) + intval($fields[12]);   // utime + stime
            $rss_kb = intval(intval($fields[21]) * 4096 / 1024);  // rss 页数 -> KB

            $cmdline = (string) @file_get_contents($dir . '/cmdline');
            $command = trim(str_replace(chr(0), ' ', $cmdline));
            if ($command === '') {
                $command = '[' . $comm . ']';
            }
            if (strlen($command) > 160) {
                $command = substr($command, 0, 160) . '...';
            }

            $user = '';
            $status = @file_get_contents($dir . '/status');
            if ($status && preg_match('/Uid:\s+(\d+)/', $status, $u)) {
                $uid = intval($u[1]);
                $pw = function_exists('posix_getpwuid') ? @posix_getpwuid($uid) : null;
                $user = $pw ? $pw['name'] : (string) $uid;
            }

            $list[$pid] = [
                'ticks' => $ticks,
                'rss_kb' => $rss_kb,
                'command' => $command,
                'user' => $user,
            ];
        }
        return $list;
    }

    // 时钟滴答 -> 可读的累计 CPU 时间（相当于 top 的 TIME+ 列）
    private function formatTicks($ticks)
    {
        $sec = intval($ticks) / 100;
        $h = floor($sec / 3600);
        $m = floor(($sec - $h * 3600) / 60);
        $s = $sec - $h * 3600 - $m * 60;
        if ($h > 0) {
            return sprintf('%d:%02d:%04.1f', $h, $m, $s);
        }
        return sprintf('%d:%04.1f', $m, $s);
    }

    private function toSize($size)
    {
        if ($size < 1024) {
            return $size . ' B';
        }
        if ($size < 1048576) {
            return round($size / 1024, 2) . ' KiB';
        }
        if ($size < 1073741824) {
            return round($size / 1048576, 2) . ' MiB';
        }
        return round($size / 1073741824, 2) . ' GiB';
    }
}
