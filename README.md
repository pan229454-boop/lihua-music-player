# 分支说明：original-backup（原版快照）

本分支是 **main 之前的历史版本快照**（最后一个提交 `4c7493b5`，2026-10-05，共 91 个提交），保留用于备份、回溯与差异对比，**不再维护**。

## 与本仓库其他分支的关系

| 分支 | 说明 |
| --- | --- |
| `main` | 修复版（默认分支），最新代码都在这里 |
| `original-backup` | 本分支：原版快照，只加这份 README，其余代码保持原样 |

## 与 main 的主要差异

main 在本分支基础上做了这些修复与新增：

- 试听 / 取歌：接口故障不再误报「未找到任何歌曲」，新增音乐接口地址 + 接口格式配置，内置 `api.vkeys.cn` 免费兜底
- 后台「更多设置」：新增不依赖 layui 渲染的开关（域名授权 / 音乐授权），避免开关不显示
- 掉登录：改为按设备独立会话（`gui_user_session`），支持登录保持天数
- 安全审计修复：SQL 注入、歌单越权、后台口令策略、插件下载等问题
- 新增：首页留言板、6 套首页主题 + 登录页主题联动、控制面板监控、服务器状态
- 移除：`public/phpinfo.php`（公网可直接访问 phpinfo，泄漏服务器信息）

本分支额外保留、main 上也仍然存在的文件：`app/controller/SongController.php`、`extend/PHPMailer/{MusicSourceRegistry,MusicSourceService,MusicApiSettings,ServerAuthSettings,ShyMusic,MailSettings}.php`、`view/admin/advanced/`、`public/cs.php`。

## 如何对比

GitHub 仓库页 → Compare → 选择 `original-backup` → `main`。
