# Sunny 主题验证

运行菜单接管与子菜单检查：

```sh
php themes/sunny/tests/menu.php
```

使用真实主题布局和固定公开数据，验证插件未安装、未接管、旧版单层 API、三级菜单与空菜单，覆盖父项链接、祖先高亮、转义、安全新窗口和手机底部前四个顶级入口。无需加载核心入口、数据库或账号配置；扩展仓库的 `scripts/test.php` 会自动运行此项。

运行主题标签与公开布局数据检查：

```sh
php themes/sunny/tests/comments.php
```

提供本地 SBlog 核心源码可追加真实评论渲染集成检查：

```sh
php themes/sunny/tests/comments.php --core=/path/to/SBlog/index.php
```

集成检查从核心源码提取评论渲染函数，用虚构数据执行，不加载核心入口、数据库、配置或缓存。需要 PHP DOM 扩展。检查访客、管理员、空评论、关闭评论、错误、预选回复、审核通知和数量超过当前可见窗口等状态，验证核心表单字段、插件身份/元信息钩子、公开 JSON 字段及脚本转义。

浏览器中另外检查明暗模式、宽窄屏、回复与取消、键盘焦点、触屏回复按钮，以及多级回复与缺失父评论的显示。

## 首页多图拼图

```sh
php themes/sunny/tests/gallery.php --core=/path/to/SBlog/index.php
```

需要 PHP DOM 扩展和本地 SBlog 核心源码。测试只提取核心真实的 URL、安全及公开内容门禁函数，不启动核心入口或读取数据库、配置、缓存。使用虚构文章验证 0～10 张图片、封面优先与正文顺序、Markdown/HTML/裸链接及相对地址、去重与属性转义、代码和注释排除、密码文章及回复可见内容不泄漏，以及最多九张缩略图、额外原图保留与剩余数量标记。

同时用内存中的公开用户资料验证文章作者与站长昵称、空昵称回退用户名、作者缺失时回退站长、按作者缓存查询，以及卡片昵称和头像标签的 HTML 转义。

浏览器中另外检查各图片数量的排列、明暗模式、手机宽度、图片查看器的键盘与触屏操作，以及关闭查看器后的焦点和滚动恢复。

## Steam 与音乐播放器

```sh
php themes/sunny/tests/steam.php
php themes/sunny/tests/steam.php --without-plugin
php themes/sunny/tests/music.php
php themes/sunny/tests/music-auth.php
```

Steam 检查使用插件真实渲染器及虚构 snapshot，验证可选插件缺失、未配置、无玩家资料、隐私数量、在线状态、缓存提示、转义和游戏页开关。音乐检查使用注入的网易云响应及独立临时缓存，验证歌单链接、固定官方请求地址、元数据归一化、缓存更新／失效／隐私清除、歌曲上限与安全 HTML。均不访问实际账号或网络。

`music-auth.php` 使用虚构 Cookie 和注入响应，检查凭证白名单、请求签名、账号与歌曲缓存隔离、过期重取、试听识别、CDN 地址限制、Cookie 失效与异常信息保护，不读取真实 Cookie。

安装临时 Playwright 依赖后运行浏览器行为检查：

```sh
NODE_PATH=/path/to/node_modules node themes/sunny/tests/music-auth.cjs
```

可通过 `PLAYWRIGHT_CHROMIUM_EXECUTABLE` 指定本地 Chromium。测试拦截全部网络并模拟音频，验证鉴权请求、地址刷新、失败切歌、暂停取消请求、失效提示、循环边界和 PJAX 保留音频；不验证实际会员歌曲能否播放。

浏览器中另外检查 Steam 搜索、排序、48 款分页及焦点；音乐歌单抽屉、播放／暂停／停止、上一首／下一首、循环与随机、拖动进度、音量、无法播放的歌曲、刷新后手动恢复播放。播放器初始不请求音频，手机控制条避开底部导航。所有界面需检查浅色／暗色、宽屏／手机、键盘及减少动画设置。
