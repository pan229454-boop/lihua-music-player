<?php

namespace {
}
namespace app\controller {
    class Api extends Common {
        public function clearCache() {
            // 修复：原为匿名可调用，任何人可反复清空全站缓存
            $check = \app\model\Users::checkLogin();
            if ($check !== null) {
                return json(['code' => -1, 'msg' => $check['msg']]);
            }
            if (\think\facade\Cache::clear()) {
                $result = ['code' => 0, 'msg' => '成功清除所有缓存'];
            } else {
                $result = ['code' => -1, 'msg' => '清除缓存失败'];
            }
            return json($result);
        }
        public function playerinfo() {
            $act = input('get.');
            $id = $act['id'];
            if (!$id) {
                $result['code'] = -1;
                return json($result);
            }
            if ((bool)\app\model\Player::checkPlayerKey($id)) {
                $result['code'] = -1;
                return json($result);
            }
            $player = \app\model\Player::where('id', $id)->find();
            $data = ['plays' => $player['plays'] + 1, 'endtime' => date('Y-m-d H:i:s')];
            \app\model\Player::where('id', '=', $id)->data($data)->update();
            $data2 = ['player_id' => $id, 'user_id' => $player['user_id'], 'side' => 'ios', 'create_time' => date('Y-m-d H:i:s')];
            \app\model\Plays::add($data2);
            $cache = \think\facade\Cache::get('info' . $id);
            if ($cache) {
                return response($act['jsoncallback'] . '(' . $cache . ')');
            }
            $result = [];
            $songSheetList = [];
            $songSheets = \app\model\PlayerSongSheet::alias('pss')->join('song_sheet ss', 'ss.id=pss.song_sheet_id')->field('ss.*')->where('pss.player_id', $id)->order('pss.taxis asc')->select();
            foreach ($songSheets as $key => $item) {
                $songs = \app\model\Song::where('song_sheet_id', $item['id'])->order('taxis asc')->select();
                $songlists = [];
                foreach ($songs as $key2 => $item2) {
                    $songlists[$key2] = ['type' => $item2['type'], 'id' => $item2['song_id'], 'name' => $item2['name'], 'cover' => $item2['album_cover'], 'artist' => $item2['artist_name'], 'album' => $item2['album_name'], 'url' => $item2['location'], 'lyric' => $item2['lyric']];
                }
                $songSheetList[$key] = ['SheetName' => $item['name'], 'author' => $item['author'], 'songs' => $songlists];
            }
            $result = ['playerName' => $player['name'], 'showGreeting' => $player['show_greeting'], 'switchopen' => $player['switchopen'], 'time' => $player['time'], 'showLrc' => $player['show_lrc'], 'showMsg' => $player['showmsg'], 'defaultAlbum' => $player['default_album'], 'randomPlayer' => $player['random_player'], 'defaultVolume' => $player['default_volume'], 'greeting' => $player['greeting'], 'autoPlayer' => $player['auto_player'], 'Sheetlist' => $songSheetList, 'showNotes' => $player['show_notes']];
            $result = json_encode($result);
            \think\facade\Cache::set('info' . $id, $result);
            return response($act['jsoncallback'] . '(' . $result . ')');
        }
        public function musicUrl() {
            $data = input('get.');
            if (!(isset($data['sign']) && isset($data['songId']) && isset($data['type']) && isset($data['id']))) {
                return abort(400, 'Invalid sign');
            }
            $url = '';
            // 开关关闭、或者授权QQ/skey 没填全时，跳过授权验证，直接取歌
            if (auth_qq_ready()) {
                $queryParams = http_build_query(['qq' => \think\facade\Config::get('api.qq'), 'skey' => \think\facade\Config::get('api.skey'), 'demo' => $_SERVER['HTTP_HOST']]);
                // 换成带超时的 curl，上游异常时返回提示而不是 500
                $response = curl('https://auth.cenguigui.cn/MusicApi.php?' . $queryParams, ['ctime' => 5, 'rtime' => 8]);
                if (empty($response)) {
                    die('请求失败，请稍后再试。');
                }
                $responseData = json_decode($response, true);
                if (!is_array($responseData)) {
                    die('授权服务返回异常，请稍后再试。');
                }
                if ($responseData['status'] == 'error' && $responseData['message'] == '域名未授权') {
                    $url = 'https://cdn.cenguigui.cn/Api/tts/2024-12-14-185658_111237.mp3';
                } elseif ($responseData['status'] == 'error' && $responseData['message'] == 'skey有误') {
                    $url = 'https://cdn.cenguigui.cn/Api/tts/2024-12-14-190011_184063.mp3';
                }
            }
            if ($url === '') {
                // 授权通过（或跳过授权验证）后，去音乐接口取真实播放地址
                $type = $data['type'];
                $id = $data['songId'];
                $json = send_get(music_api_url() . '?input=' . $id . '&filter=id&type=' . $type . '&page=1&url=' . $_SERVER['SERVER_NAME']);
                $musicData = json_decode($json, true);
                $url = $musicData['url'] ?? '';
				if ($url === '') {
					$vkeysAlt = music_vkeys_resolve($type, $id);
					if ($vkeysAlt) { $url = $vkeysAlt['url']; }
				}
            }
            // 修复：原实现可能 redirect(null) 或跳到非预期地址
            if (!is_safe_remote_url($url)) {
                die('音乐地址不可用：' . music_api_host() . ' 没有返回播放地址（内置免费接口也没取到），请稍后再试。');
            }
            return redirect($url);
        }
        private function generateSign($songId, $timestamp) {
            $key = 'mqyybfqyyds';
            $baseString = $songId . ':' . $timestamp . ':' . $key;
            $charArray = [];
            for ($i = 0; $i < strlen($baseString); ++$i) {
                $charCode = ord($baseString[$i]);
                $charCode = $charCode + $i % 7 * 3 ^ $i % 5;
                $charArray[] = $charCode;
            }
            $hexString = '';
            foreach ($charArray as $num) {
                $hexString .= str_pad(dechex($num), 2, '0', STR_PAD_LEFT);
            }
            return strrev($hexString);
        }
        private function getOriginalDomain($domain) {
            if (strpos($domain, 'http://') !== false) {
                $domain = str_replace('http://', '', $domain);
            } else {
                $domain = str_replace('https://', '', $domain);
            }
            $strdomain = explode('/', $domain);
            return $strdomain[0];
        }
        private function getTopLevelDomain($domain) {
            $parts = explode('.', $domain);
            $count = count($parts);
            if ($count > 2) {
                $tld = array_slice($parts, -2);
                return implode('.', $tld);
            }
            return implode('.', array_slice($parts, -2));
        }
        public function musicLyric() {
            $data = input('get.');
            $cache = \think\facade\Cache::get('musicLyric' . ($data['type'] ?? '') . ($data['id'] ?? ''));
            if (($data['type'] ?? '') == 'local') {
                $id = $data['id'] ?? '';
                $url = $data['url'] ?? '';
                // 修复：type=local 原来可传 file:// 等地址读取本地文件/探测内网，现仅允许公网 http/https
                $result = is_safe_remote_url($url) ? send_get($url) : '';
                $result = ['file' => 'null', 'time' => date('Y-m-d H:i:s', time()), 'type' => 'lrc', 'txt' => $result];
            } else {
                $player = \app\model\Player::where('id', '=', $data['id'] ?? '')->find();
                if (!$player) {
                    // 修复：播放器不存在时原实现对 null 取下标，直接 500
                    $empty = json_encode(['file' => 'null', 'time' => date('Y-m-d H:i:s', time()), 'type' => 'lrc', 'txt' => '']);
                    return response(($data['jsoncallback'] ?? 'callback') . '(' . $empty . ')');
                }
                $userInfo = \app\model\Users::where('uid', '=', $player['user_id'])->find();
                if (!$userInfo) {
                    $userInfo = ['power' => -1];
                }
                if ($userInfo['power'] == 2) {
                    $json = send_get(music_api_url() . '?input=' . $data['songId'] . '&filter=id&type=' . $data['type'] . '&page=1&url=' . $_SERVER['SERVER_NAME']);
                    $apiData = json_decode($json, true);

                    if (!is_array($apiData)) {

                        // 修复：上游不可用时不再对 null 取下标导致 500，返回空歌词

                        $apiData = [];

                    }

                    $result = ['file' => 'null', 'time' => date('Y-m-d H:i:s', time()), 'type' => 'lrc', 'txt' => $apiData['lrc'] ?? ''];
                } elseif ($data['type'] == 'kugou') {
                    $ksctext = send_get(\think\facade\Config::get('api.getksc') . '?s=' . $data['songId']);
                    if ($ksctext == '') {
                        $json = send_get(music_api_url() . '?input=' . $data['songId'] . '&filter=id&type=' . $data['type'] . '&page=1&url=' . $_SERVER['SERVER_NAME']);
                        $apiData = json_decode($json, true);

                        if (!is_array($apiData)) {

                            // 修复：上游不可用时不再对 null 取下标导致 500，返回空歌词

                            $apiData = [];

                        }

                        $result = ['file' => 'null', 'time' => date('Y-m-d H:i:s', time()), 'type' => 'lrc', 'txt' => $apiData['lrc'] ?? ''];
                    } else {
                        $result = ['file' => \think\facade\Config::get('api.getksc') . '?s=' . $data['songId'], 'time' => date('Y-m-d H:i:s', time()), 'type' => 'ksc', 'txt' => $ksctext];
                    }
                } else {
                    $ksc = isset($data['ksc']) ? $data['ksc'] : null;
                    // 修复：原来把外部传入的地址直接交给 file_get_contents/send_get，
                    // 可用 file:// 等协议读取本地文件或探测内网；现只允许公网 http/https 地址
                    $ksc = is_safe_remote_url($ksc) ? str_replace(' ', '', $ksc) : null;
                    $res = $ksc ? @file_get_contents($ksc, null, null, 0, 10) : false;
                    if ($res) {
                        $ksctext = send_get($ksc);
                        $result = ['file' => $ksc, 'time' => date('Y-m-d H:i:s', time()), 'type' => 'ksc', 'txt' => $ksctext];
                    } else {
                        $json = send_get(music_api_url() . '?input=' . $data['songId'] . '&filter=id&type=' . $data['type'] . '&page=1&url=' . $_SERVER['SERVER_NAME']);
                        $apiData = json_decode($json, true);

                        if (!is_array($apiData)) {

                            // 修复：上游不可用时不再对 null 取下标导致 500，返回空歌词

                            $apiData = [];

                        }

                        $result = ['file' => 'null', 'time' => date('Y-m-d H:i:s', time()), 'type' => 'lrc', 'txt' => $apiData['lrc'] ?? ''];
                    }
                }
                \think\facade\Cache::set('musicLyric' . ($data['type'] ?? '') . ($data['id'] ?? ''), json_encode($result));
            }
            $result = json_encode($result);
            return response(($data['jsoncallback'] ?? 'callback') . '(' . $result . ')');
        }
        public function mainColor() {
            $url = input('get.url');
            // 修复：只接受公网 http/https 图片地址（原来 file:// 等协议会进入 getimagesize/imagecreatefrom*）
            if ($url && !is_safe_remote_url($url)) {
                $url = null;
            }
            $cache = \think\facade\Cache::get('mainColor' . $url);
            if ($cache) {
                return response($cache);
            }
            $result = 'var cont =';
            if ($url != null && $url != '') {
                [$r, $g, $b] = mainColor($url);
                $result .= "'" . $r . ',' . $g . ',' . $b . "'";
                $grayLevel = $r * 0.299 + $g * 0.587 + $b * 0.114;
                if ($grayLevel >= 150) {
                    $result .= ";font_color='0,0,0';";
                } else {
                    $result .= ";font_color='255,255,255';";
                }
            } else {
                $result .= "'0,0,0';font_color='255,255,255';";
            }
            \think\facade\Cache::set('mainColor' . $url, $result);
            return $result;
        }
        public function PlayerJs($id) {
            $player = \app\model\Player::where('id', '=', $id)->find();
            if (!$player) {
                // 修复：原来播放器不存在时对 null 取下标直接 500
                return response('console.log("player not found");')->contentType('application/javascript');
            }
            $user = \app\model\Users::where('uid', '=', $player['user_id'])->find();
            if (!$user) {
                $user = ['power' => -1];
            }
            $theme = $user['power'] == 2 ? 1 : $player['theme'];
            $filePath = getcwd() . '/static/guiloveyou/' . $theme . '/player/js12355555555/player.js';
            if (!is_file($filePath)) {
                // 修复：皮肤 2/3 的脚本目录名是 js（没有 js12355555555），原来固定拼目录名会导致取不到文件、播放器空白
                $altPath = getcwd() . '/static/guiloveyou/' . $theme . '/player/js/player.js';
                if (is_file($altPath)) {
                    $filePath = $altPath;
                }
            }
            $loadJQuery = $player['jquery'];
            $playerIdScript = "var playerId = '" . $id . "';";
            $playerScriptContent = is_file($filePath) ? json_encode(file_get_contents($filePath)) : '""';
            if ($loadJQuery == 1) {
                $script = "$playerIdScript\nvar script = document.createElement(\"script\");\nscript.src = \"https://lf3-cdn-tos.bytecdntp.com/cdn/expire-1-M/jquery/3.6.0/jquery.min.js\";\nscript.onload = function() {\n    loadPlayerScript();\n};\ndocument.head.appendChild(script);\n\nfunction loadPlayerScript() {\n    var playerScript = document.createElement(\"script\");\n    playerScript.textContent = $playerScriptContent;\n    document.head.appendChild(playerScript);\n}";
            } else {
                $script = "$playerIdScript\nvar playerScript = document.createElement(\"script\");\nplayerScript.textContent = $playerScriptContent;\ndocument.head.appendChild(playerScript);";
            }
            return response($script)->contentType('application/javascript');
        }
        public function PlayerCss($id) {
            $player = \app\model\Player::where('id', '=', $id)->find();
            if (!$player) {
                // 修复：播放器不存在时原来对 null 取下标直接 500
                return response('', 404);
            }
            $user = \app\model\Users::where('uid', '=', $player['user_id'])->find();
            if (!$user) {
                $user = ['power' => -1];
            }
            $theme = $user['power'] == 2 ? 1 : $player['theme'];
            $url = get_domain() . 'static/guiloveyou/' . $theme . '/player/css/player.css';
            return redirect($url);
        }
    }
}
