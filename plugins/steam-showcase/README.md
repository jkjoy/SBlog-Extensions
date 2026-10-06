# Steam 游戏展示

基于 Steam 官方 Web API 的 SBlog 插件。独立展示页包含玩家昵称、头像、同步时的在线/游戏状态、过去两周游玩记录、游戏库和时长统计；游戏库支持搜索、按时长/名称排序和加载更多。可选在首页文章列表下方展示紧凑卡片。

## 安装与配置

1. 将整个 `steam-showcase` 目录放到博客的 `plugins/` 下。
2. 在后台「插件管理」启用 **Steam 游戏展示**，点击它的「设置」。
3. 填写 17 位 **SteamID64** 和 32 位 **Steam Web API Key**。密钥从 [Steam 官方申请页面](https://steamcommunity.com/dev/apikey) 获取。
4. 保存后点击「立即同步 Steam 数据」，再进入「查看展示页」。

展示页固定使用 `index.php?a=steam`，兼容伪静态和子目录部署。默认主题导航会自动增加入口，可在设置中关闭。自定义主题请手动添加该地址；首页卡片需要主题保留 `content_after` 钩子。插件设置只通过「插件管理」进入，不额外添加后台侧栏菜单。

在 Steam「编辑个人资料 → 隐私设置」中，将个人资料与游戏详情设置为公开；展示完整时长还需关闭「始终将我的总游戏时间保密」。API 无法读取未公开的数据。Web API 返回的游戏名称以 Steam 返回值为准。

## 展示与缓存

- 默认每批展示 12 个游戏，可配置为 1–24；没有 JavaScript 时游戏库仍可阅读。
- 过去两周指 Steam 返回的 `playtime_2weeks`；累计时长使用 `playtime_forever`，单位为分钟。
- 游戏库统计包含 API 返回的已购买游戏及玩过的免费游戏，不等同于 Steam 商店所有免费授权。
- 默认缓存 15 分钟，可配置为 5–1440 分钟。在线状态是同步时的快照，不会每秒轮询。
- 缓存过期后首次访问同步；并发请求使用文件锁避免重复调用。请求失败有 60 秒冷却，并保留最多 7 天前的成功结果，前台标注上次同步时间。
- 空游戏库、未公开游戏详情、未配置与接口故障分别显示说明。Steam 成功返回隐藏状态时不继续展示先前缓存的公开游戏。
- API Key 只存放在服务器数据库的独立 `plugin_steam_settings` 表，不进入核心 `settings` 或 `cache/settings.php`，不回填 HTML 表单。API 返回内容输出前经过转义。
- 数据缓存位于 `cache/`，按账号与密钥的摘要区分，不包含 API Key。保留项目自带的 `data/`、`cache/` 和插件源码访问限制。

## 使用的官方接口

所有请求由服务器通过 HTTPS 发出，仅连接 `api.steampowered.com`：

| 接口 | 用途 |
| --- | --- |
| `ISteamUser/GetPlayerSummaries/v0002/` | 玩家资料、头像与状态 |
| `IPlayerService/GetRecentlyPlayedGames/v0001/` | 最近两周游玩记录 |
| `IPlayerService/GetOwnedGames/v0001/` | 游戏库、名称、游玩时长 |

游戏图来自 Steam CDN，游戏卡片链接到 Steam 商店。游戏列表不依赖商店抓取、第三方代理或浏览器直接调用 Steam API。

官方文档：[ISteamUser](https://partner.steamgames.com/doc/webapi/ISteamUser)、[IPlayerService](https://partner.steamgames.com/doc/webapi/IPlayerService)。

## 运行要求与验证

需要 SBlog 当前插件/主题钩子、PHP 8.0+、PDO SQLite 与 cURL，以及服务器访问 Steam API 的能力。插件不修改核心程序、账号资料或博客文章，停用后展示入口消失；配置与缓存保留以便再次启用。

无需真实账号或密钥的本地回归检查：

```sh
php plugins/steam-showcase/tests/run.php
```

测试说明见 [tests/README.md](tests/README.md)。
