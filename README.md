# 梨花带雨 · 音乐播放器整站

基于 PHP 8.1 + ThinkPHP 8 的音乐播放网站整站代码：前台多套主题播放器、歌单与歌曲管理、后台管理、首页留言板、域名授权与音乐接口配置。

## 功能

**前台**
- 多套播放器主题（默认 / 科技 / 极简 / 极光 / 商务等），登录页、注册页主题可跟随首页
- 歌单展示、多平台导入（QQ音乐 / 酷狗 / 网易云）、歌曲搜索
- 试听、歌词、专辑封面、播放进度
- 首页留言板（后台可开关）

**后台（layuiadmin）**
- 控制面板监控、服务器状态
- 更多设置：站点信息、邮箱、登录保持天数、域名授权开关、音乐授权
- 音乐授权：授权 QQ / skey 开关，音乐接口地址与接口格式（auto / 默认格式 / 内置免费接口）
- 播放器编辑、歌单与歌曲管理、用户管理、设备会话、留言管理
- 插件下载（WordPress / Typecho / Emlog / ZBlog / 通用嵌入）

**安全与稳定**
- 按设备独立会话（避免掉登录）
- 数据库操作参数绑定、歌单归属越权校验、后台权限校验
- 试听 / 取歌失败会明确提示原因（接口不可用 vs 歌曲不存在）

## 技术栈

PHP 8.1+ · ThinkPHP 8.1 · MariaDB / MySQL · layui + layuiadmin · jQuery

## 目录结构

    app/         应用代码（controller / model / common.php 公共函数）
    config/      配置（数据库、路由、缓存等）
    route/       路由
    view/        模板（admin 后台片段、console SPA 外壳、index 前台主题）
    public/      站点根目录（static 静态资源、res 后台资源、down 插件包）
    extend/      扩展库（PHPMailer、Epay 等）
    vendor/      Composer 依赖
    install.sql  建库脚本
    deploy/      修复记录、审计报告、运维脚本

## 部署

1. 建库并导入：`mysql -u用户 -p 库名 < install.sql`
2. 编辑 `.env`：数据库地址、账号、密码（本仓库不含 .env）
3. 站点根目录指向 `public/`，PHP 8.1 以上；需要 fileinfo / gd / curl 等常用扩展
4. Nginx 伪静态（宝塔「伪静态」里贴）：

       location ~* (runtime|application)/{ return 403; }
       location / { if (!-e $request_filename){ rewrite ^(.*)$ /index.php?s=$1 last; break; } }

5. 本地快速跑起来：

       php -S 127.0.0.1:8080 -t public public/router.php

6. 后台入口 `/console`，初始账号 `admin / 123456` —— **上线第一件事就是改密码**

## 音乐接口说明

- 默认接口：`https://musicapi.cenguigui.cn`（试听与取歌都走它）
- 后台「更多设置 → 音乐授权」可改「音乐接口地址」和「接口格式」：
  - auto（默认）：先用你填的接口，取不到就用内置的 `api.vkeys.cn` 兜底
  - 默认格式：只用你填的接口
  - 内置免费接口：只用 `api.vkeys.cn`
- 内置免费接口目前只支持 QQ 音乐（按 mid 精确取播放地址，免费额度是低码率试听流）；网易云 / 酷狗需要自备可用接口
- 上游接口宕机时会给明确提示，不会再把「接口故障」误报成「未找到任何歌曲」

## 分支

| 分支 | 说明 |
| --- | --- |
| `main` | 修复版，后续更新都提交到这里（默认分支） |
| `original-backup` | 2026-10-05 的原版快照，仅作备份与对比，不再维护 |

## 说明

仅供学习与自建个人音乐站使用，音乐版权归各音乐平台所有，请勿用于商业分发。
