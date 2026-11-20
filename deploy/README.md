# 梨花带雨音乐播放器 · 容器内部署说明

## 一、环境

| 项 | 值 |
|---|---|
| 运行环境 | 本机 PRoot 容器（Ubuntu 24.04 / aarch64） |
| 应用 | ThinkPHP 8.0.0（vendor 随包提供，无需 composer） |
| PHP | 8.3.6（pdo_mysql / curl / gd / mbstring / zip / bcmath / intl / openssl） |
| 数据库 | MariaDB 10.11.14，127.0.0.1:3306 |
| Web | PHP 内置服务器 + public/router.php，127.0.0.1:8080 |
| 项目目录 | /root/Documents/deepseek-harness/default-workspace/lihua-music |
| 部署来源 | /storage/emulated/0/MT2/mcp/梨花带雨音乐播放-授权版-开源.zip（2175 个文件，原样解压） |

## 二、常用命令

```bash
bash deploy/start-db.sh      # 启动 MariaDB
bash deploy/start-web.sh     # 启动站点（默认 127.0.0.1:8080）
bash deploy/stop-all.sh      # 全部停止
```

## 三、访问

- 前台首页 http://127.0.0.1:8080/
- 控制台（含登录/注册） http://127.0.0.1:8080/console
- 后台管理 http://127.0.0.1:8080/admin
- 账号 **admin / 123456**

## 四、架构要点（控制台如何取页面）

1. `view/console/index.html` 是控制台外壳，加载 `/res/index.js` → `/res/config.js`（setter）+ `/res/adminui/dist/modules/*`（The Web UI Theme v2.5.2）。
2. `/res/config.js` 里 **`paths.views = '/admin/'`**，主题渲染视图的规则是
   `url = paths.views + 路由 + '.html'`，即登录页请求的是 **`/admin/user/act/login.html`**。
3. 也就是说：**业务视图不是静态文件，而是交给 PHP（ThinkPHP）在 `/admin/...` 渲染的**（登录页对应 `view/user/login.html`，后台各页对应 `view/admin/*.html`）。

## 五、与原始压缩包的差异（本次部署所做的修改）

> 结论：zip 解压目录与原来那份目录**内容完全一致**（2175 个文件逐一比对，0 处差异），
> 所有问题都来自包本身与运行环境的组合，重新解压不会改变任何行为。

| # | 文件 | 问题 | 处理 |
|---|---|---|---|
| **1（真正的根因）** | `app/common.php:473` | `require_once '../extend/Epay/epay_submit.class.php'` 是**依赖当前工作目录的相对路径**。控制台请求的视图 URL 带 `.html` 后缀（`/admin/user/act/login.html`），PHP 内置服务器处理这类请求时工作目录不是 `public/`，这行 require 找不到文件 → **PHP Fatal error → 空 500** → 控制台弹出"请求视图文件异常，状态：500"。Nginx/Apache+PHP-FPM 下工作目录恰为站点根目录，所以作者原版不会触发 —— 这就是"换环境就坏"的原因 | 改为 `require_once __DIR__ . '/../extend/Epay/epay_submit.class.php'`（与工作目录无关） |
| 2 | `app/controller/Epay.php:12`、`public/qqlogin/qq/{action,loginlog,manage}.php` | 同样写法的相对 `require/include`（`../extend/...`、`../comm/...`），同类隐患 | 统一改为 `__DIR__` 绝对路径 |
| 3 | `view/user/login.html` | 登录按钮依赖腾讯验证码 `new TencentCaptcha('2046626881',...)`，内网/域名未授权时脚本加载不出来，页面点了没反应 | 保留验证码；当 `TencentCaptcha` 不存在时自动降级为直接提交（服务端配合第 5 条已容忍验证码接口失效） |
| 4 | `app/controller/LoginAjax.php` | 验证码校验走 `cgi.urlsec.qq.com`，该接口现已恒返回 `reCode=-101`（方法返回 -1=接口已失效）；原逻辑把"接口失效"当成"验证码错误"，导致永远登录失败 | 仅在明确判定验证码错误（返回 0）时拒绝，接口不可用（返回 -1）时放行 |
| 5 | `app/common.php` `get_ip_city()` | 用 `file_get_contents` 调 `api.cenguigui.cn` 取登录地，上游 521 时抛异常 → 登录瞬间 500 | 改用带超时的 curl，失败降级为"未知地址" |
| 6 | `app/controller/Api.php` `musicUrl()` | 同样用 `file_get_contents` 调授权接口，异常时 500 | 改用 `curl()` + 超时 + 非 JSON 兜底 |
| 7 | `app/controller/Webapi.php` | `checkAndUpdateUserLimit(string $token)` 形参强类型，未传 token 抛 TypeError → 500 | 去掉强制类型，缺 token 返回 JSON(403) |
| 8 | `public/res/views/user/{login.html,act/*}` | **走错路的产物**：一开始以为控制台读静态视图，按 `indPage` 补了 `act/` 目录并重写了登录页；实际控制台读的是 `/admin/*.html`（见第四节），这些文件当前**不被使用**，保留无害 | 保留，仅作说明 |

## 六、尚未解决的限制

1. **注册功能**：`LoginAjax::reg()` 需要邮箱验证码，依赖 PHPMailer + SMTP 配置，包内未配置，故注册不可用。
2. **音乐播放**：需官方授权码（`app/controller/Common.php` 里的 qq/skey，配合 `auth.cenguigui.cn` 域名授权），未做任何绕过。
3. 腾讯验证码在 127.0.0.1 下未必可用（域名未在腾讯验证码控制台授权），已做降级处理。
4. 容器重启后需手动执行启动脚本（PRoot 无 systemd）。
5. 内置 PHP 服务器仅供本机调试，长期运行建议换 Nginx + PHP-FPM（换过去之后第 1、2 条的绝对路径写法同样成立）。
