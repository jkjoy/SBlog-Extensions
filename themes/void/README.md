# VOID for Simple PHP Blog

基于 [AlanDecode / Typecho-Theme-VOID](https://github.com/AlanDecode/Typecho-Theme-VOID) 3.5.1 的独立移植，参考提交 `a70bbc9a173b7f80ebf6dcd386e4286a8a7fbcb2`。直接使用原版 SCSS、图标字体、正文排版及代码高亮样式，保留原作者 MIT 许可；PHP 模板和浏览器交互适配 Simple PHP Blog，不需要 Typecho 或配套插件。

主题维护在 [jkjoy/SBlog-Extensions](https://github.com/jkjoy/SBlog-Extensions/tree/main/themes/void)，适配并已验证 Simple PHP Blog **1.14.16**。

## 安装

推荐在后台「扩展商店」搜索 **VOID** 并安装，再到「主题管理」预览或启用 **VOID · 无类型**。

也可从 [1.0.0 发布页](https://github.com/jkjoy/SBlog-Extensions/releases/tag/theme-void-v1.0.0) 下载 `theme-void-1.0.0.zip`，将包内 `void/` 目录复制到 SBlog 的 `themes/void/`，然后在后台启用。无需 Node.js 或构建步骤。

## 已实现

- 原版黑色导航、青色错位站名、单/双栏文章卡片、三种封面布局、可配置头图、移动导航及分类下拉菜单。
- 文章、独立页、归档、分类、标签、友链、分页、404；站内搜索支持中文，并只检索公开内容。
- 日间、夜间、系统与时间自动切换；Sans/Serif、14–22px 字号；阅读偏好在浏览器保存。
- PJAX 导航、浏览器前进后退与滚动位置恢复；包含额外脚本的页面自动使用完整导航。
- 前台弹窗登录，复用核心登录与 CSRF，支持错误反馈，成功后保留当前博客地址并刷新身份。
- AJAX 评论、回复/取消、表情选择器、审核反馈；保留核心 CSRF、限流、蜜罐及评论插件信息。
- 核心真实点赞计数与访客去重、浏览次数、公开字数、预计阅读时间、上一篇/下一篇、分享与赞赏二维码。
- 自动文章目录、当前章节标记、返回顶部、代码高亮、Mac 风格代码块、行号与代码复制。
- 图片原生懒加载、可使用键盘的图片灯箱、图组、提示块、注音；按需加载数学公式渲染。
- canonical、Open Graph、JSON-LD、RSS、站点地图、自定义 head、页脚及备案信息。
- 密码文章与回复隐藏门禁保持核心行为，隐藏正文不参与卡片封面、摘要、搜索及公开字数统计。

## 配置

编辑 `config.php`，刷新即生效。升级时保留该文件。站名、副标题、SEO、每页数量、评论审核、favicon、页脚和备案号沿用博客后台设置。

| 配置 | 用途 |
| --- | --- |
| `name` / `index_title` / `index_subtitle` | 导航站名、首页标题、副标题；空值沿用博客设置 |
| `banner` / `banner_height` / `mobile_banner_height` | 首页/索引头图及桌面/手机高度，单位 vh |
| `index_style` | `grid` 双栏，`single` 单栏 |
| `cover_style` / `auto_cover` / `post_banner` | 0 纯文字、1 顶部封面、2 背景封面；从公开正文首张图片取图；详情头图开关 |
| `color_scheme` / `follow_system` / `dark_start` / `dark_end` | 默认配色与自动时段；访客个人选择优先 |
| `font_size` / `serif` | 默认字号档位和衬线字体，使用可用的本地字体 |
| `mac_code` / `line_numbers` / `pjax` / `math` | 对应功能开关 |
| `math_url` | MathJax 3 加载地址，可替换为自己的静态资源 |
| `show_word_count` / `show_reading_time` / `show_likes` | 字数、阅读时间和点赞开关 |
| `start_date` / `reward_image` | 建站日期（YYYY-MM-DD）和赞赏二维码 |
| `social` / `navigation` | 设置面板社交链接及自定义导航下拉组，示例见配置文件 |

除开启公式渲染时按需获取 MathJax 外，主题样式、图标、Prism 和交互脚本均来自本地。公式资源无法加载时保留原文。图片 URL 支持 HTTP(S) 或站点绝对路径。

## 写作语法

```text
{{文字:zhu yin}}

[notice]这是一条提示。[/notice]

[notice]

支持多个段落的提示。

[/notice]

[photos]

![图一](/uploads/photo-1.jpg)

![图二](/uploads/photo-2.jpg)

[/photos]
```

块标记单独占一段，前后留空行。代码块里的标记保持原样。公式使用 `$...$`、`$$...$$`；代码块使用 Markdown 围栏并指定语言。

## 开发与验证

`assets/scss/` 是保留的上游样式源文件；`assets/void.css` 为已编译样式，`style.css` 负责 SBlog 适配。下面的命令在已安装本主题的 **SBlog 核心项目根目录**执行；扩展仓库不包含核心的 `index.php` 和 `tests/`。修改原版 SCSS 后可重新编译：

```bash
npx --yes sass --silence-deprecation=import,global-builtin,color-functions themes/void/assets/scss/void.scss themes/void/assets/void.css --style=compressed --no-source-map
php themes/void/tests/smoke.php
php tests/themes.php
php tests/visibility.php
php tests/public-layout.php
node --check themes/void/assets/void.js
```

PHP DOM 扩展用于详情重排和扩展语法。缺少该扩展时保留核心完整正文与评论，降级显示。验证脚本使用内存 SQLite，不读取或修改已安装博客。

Typecho 专属的评论投票、访客互动统计及 Service Worker 缓存尚未移植。本站的后台配置仍通过 `config.php` 完成；原主题的 Typecho 设置面板不适用于 SBlog。原主题版权归 AlanDecode；PrismJS 版权见 `assets/PRISM-LICENSE`。
