<?php
namespace app\model;

use think\facade\Config;

class Song extends Base
{
	
    public static function findMusicInfo($data){
		$type = strtolower(trim((string) ($data['type'] ?? '')));
		$songid = trim((string) ($data['songid'] ?? ''));
		$api = music_api_url();
		$mode = music_api_mode();
		$json = '';
		$row = [];
		if ($mode !== 'vkeys') {
			$json = send_get($api . '?input=' . urlencode($songid) . '&filter=id&type=' . urlencode($type) . '&page=1&url=' . $_SERVER['SERVER_NAME']);
			$tmp = json_decode((string) $json, true);
			if (is_array($tmp) && !empty($tmp['url'])) {
				$row = $tmp;
			}
		}
		// 自己填的接口取不到时，用内置的免费接口兜底（目前只有 QQ 音乐）
		if (empty($row['url']) && $mode !== 'default') {
			$alt = music_vkeys_resolve($type, $songid);
			if ($alt) {
				$row = ['title' => $alt['title'], 'author' => $alt['author'], 'pic' => $alt['pic'], 'url' => $alt['url']];
			}
		}
		if (!empty($row['url'])) {
			return [
				"code" => 0,
				"song_name" => $row['title'] ?? '',
				"artist_name" => $row['author'] ?? '',
				"album_name" => $row['album'] ?? ($row['title'] ?? ''),
				"album_cover" => $row['pic'] ?? '',
				"music_url" => $row['url'],
				"location" => '',
				"lyric" => '',
			];
		}
		// 失败：说清楚是接口的问题，别再说「未找到任何歌曲」
		if (trim((string) $json) === '' && $mode !== 'default') {
			return ['code' => -2, 'msg' => '音乐接口暂时不可用：' . music_api_host() . ' 没有响应，内置的免费接口也没取到（它目前只支持 QQ 音乐），稍后再试'];
		}
		if (trim((string) $json) === '') {
			return ['code' => -2, 'msg' => '音乐接口暂时不可用：' . music_api_host() . ' 没有响应（上游服务器可能宕机了），稍后再试'];
		}
		return ['code' => -2, 'msg' => '上游音乐接口（' . music_api_host() . '）没有返回这首歌的播放地址，稍后再试'];
    }

		 function findSheetInfo($type, $songsheetid) {
    $song_list = [];

    switch ($type) {
        case 'wy':
            $post_data = ['s' => '0', 'id' => $songsheetid, 'n' => '1000', 't' => '0'];
            $url = "https://music.163.com/api/v6/playlist/detail";
            $data = Post($post_data, $url);
            $arr = json_decode($data, true);
            if (isset($arr['playlist']['tracks'])) {
                $songs = $arr['playlist']['tracks'];
                foreach ($songs as $value) {
                    $song_list[] = [
                        'type' => 'netease',
                        'song_id' => $value['id'],
                        'name' => $value['name'],
                        'artist_name' => $value['ar'][0]['name'],
                        'album_cover' => $value['al']['picUrl'] . '?param=300x300',
                        'album_name' => $value['al']['name'],
                    ];
                }
            } 
            break;

        case 'kg': 
            $data = send_get('https://www.kugou.com/yy/special/song/sid=' . $songsheetid);
            $arr = json_decode($data, true);
            if (isset($arr['data'])) {
                $songs = $arr['data'];
                foreach ($songs as $value) {
                    $song_list[] = [
                        'type' => 'kugou',
                        'song_id' => $value['HASH'],
                        'name' => $value['songname'],
                        'artist_name' => $value['singername'],
                        'album_cover' => str_replace('{size}', '150', $value['authors'][0]['sizable_avatar']),
                        'album_name' => $value['album_name'],
                    ];
                }
            }
            break;

        case 'qq': 
            $urls = [
                'https://c.y.qq.com/v8/fcg-bin/fcg_v8_toplist_cp.fcg?page=detail&tpl=macv4&type=top&topid=' . $songsheetid . '&g_tk=5381&loginUin=0&hostUin=0&format=json&inCharset=utf8&outCharset=utf-8&notice=0&platform=yqq.json&needNewCode=0',
                'https://c.y.qq.com/qzone/fcg-bin/fcg_ucc_getcdinfo_byids_cp.fcg?type=1&json=1&utf8=1&onlysong=0&new_format=1&disstid=' . $songsheetid . '&g_tk_new_20200303=637541790&g_tk=637541790&loginUin=0&hostUin=0&format=json&inCharset=utf8&outCharset=utf-8&notice=0&platform=yqq.json&needNewCode=0'
            ];

            $dataFetched = false;

            foreach ($urls as $url) {
                $get_curl = send_get($url, '', 'https://c.y.qq.com/');
                $arr = json_decode($get_curl, true);

                if ($arr) {
                    if (isset($arr["songlist"])) {
                        $songs = $arr["songlist"];
                        foreach ($songs as $value) {
                            $song_list[] = [
                                'type' => 'qq',
                                'song_id' => $value["data"]["songmid"],
                                'name' => $value["data"]["songname"],
                                'artist_name' => $value["data"]["singer"][0]["name"],
                                'album_name' => $value['data']['albumname'],
                                'album_cover' => 'https://y.gtimg.cn/music/photo_new/T002R300x300M000' . $value["data"]["albummid"] . '.jpg',
                            ];
                        }
                        $dataFetched = true;
                        break;
                    } elseif (isset($arr['cdlist'][0]['songlist'])) {
                        $songs = $arr['cdlist'][0]['songlist'];
                        foreach ($songs as $value) {
                            foreach ($value['singer'] as $val) {
                                $song_list[] = [
                                    'type' => 'qq',
                                    'song_id' => $value['mid'],
                                    'name' => $value['name'],
                                    'album_name' => $value['album']['name'],
                                    'artist_name' => $val['name'],
                                    'album_cover' => 'https://y.gtimg.cn/music/photo_new/T002R300x300M000' . $value['album']['mid'] . '.jpg',
                                ];
                            }
                        }
                        $dataFetched = true;
                        break;
                    }
                }
            }
            if (!$dataFetched) {
                $song_list = [];
            }
            break;
        default:
            return ''; 
    }
    return !empty($song_list) ? $song_list : '';
}
} 