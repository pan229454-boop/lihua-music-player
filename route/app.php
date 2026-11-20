<?php
use think\facade\Route;

Route::get('favicon', 'webapi/getFavicon');  
Route::get('music/search', 'webapi/search');
Route::get('music/info', 'webapi/info');
Route::get('music/url', 'webapi/url');
Route::get('music/list', 'webapi/playlist');
// 修复：IpLocation 控制器并不存在，该路由必然报错，已移除