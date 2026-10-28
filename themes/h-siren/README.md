# H-Siren for Simple PHP Blog

由 [galnetwen/H-Siren](https://github.com/galnetwen/H-Siren) 移植，参考版本 `79bcde23ccca45f313d7ba08d3af5e3369b598a3`（原主题 4.3.8.190419）。原主题作者为后宫学长，源自 Fuzzz 的 Akina 与蜜汁路易的 Siren。本移植保留全屏封面、圆形头像、橙色点缀、交错图文卡片、聚焦入口与高斯背景的设计。

## 安装与设置

需要 **Simple PHP Blog 1.14.18 或更高版本**。先升级核心，再将 `h-siren/` 放入 `themes/`。在「后台 → 主题管理」中点击 H-Siren 的「主题设置」，保存后可预览或启用。

设置包含首页封面、随机内置图片、头像、描述、导航 Logo、强调色、公告、列表样式、文章大图、三个聚焦入口、社交链接、图片放大、点击光点、页脚和运行天数。图片可在媒体库上传后粘贴地址；地址接受 HTTP(S) 和以 `/` 开头的站内路径，部署在子目录时应包含该目录。公告、描述和页脚按纯文本输出。

设置声明在 `settings.json` 中，实际配置按主题独立存入博客数据库，升级主题文件和切换主题不会覆盖配置，无需配套设置插件。旧版核心尚不支持 `theme_setting()`，请先升级核心，再通过扩展商店安装本主题。

站点名称、导航独立页、文章分页、备案号、SEO 和评论设置沿用博客后台。启用「菜单管理」插件接管导航时，主题使用插件菜单。统计脚本等公共代码使用「站点设置 → 页脚与代码」。

## 内容与交互

- 首页和分类、标签页显示图文列表；文章第一张公开 Markdown 图片作为封面，没有图片时使用内置图。密码文章和回复隐藏区域的图片不会被提取。
- 正文、密码门禁、回复可见、评论、评论身份和元信息由核心渲染，保留插件能力。
- 顶部搜索按已发布文章的**标题**匹配，不搜索受保护正文。保留查询词、分页与管理员主题预览状态。
- 支持手机导航、搜索弹层、图片放大、返回顶部及减少动态效果的系统偏好。所有脚本使用原生 JavaScript，CSS 与默认图片本地提供，无需 Node.js 构建。
- 归档、分类、标签、友链、独立页和错误页使用核心内容并适配主题外观。

WordPress 专用的 Options Framework、后台修改、PJAX、说说数据、旧版第三方评论、Live2D 与统计服务没有直接搬入；这类功能可通过适合 SBlog 的插件扩展。

## 来源

`assets/images/cover-1.jpg` 至 `cover-5.jpg` 分别来自原仓库的 `images/custom/default/pic_10.jpg` 至 `pic_14.jpg`；头像、页脚图标、网格与社交图标也来自该仓库。`style.css` 与 `assets/theme.js` 针对 SBlog 重新适配，不依赖 WordPress 或原版的 CDN 脚本。原始仓库的说明保留在 `UPSTREAM-README.md`。

## 回归验证

在扩展仓库根目录运行 `SBLOG_CORE_INDEX=/path/to/Simple-PHP-Blog/index.php php scripts/test-h-siren.php`，指定 SBlog 1.14.18 或更高版本的核心源码；测试仅提取函数，不启动博客、不读取现有数据库。菜单兼容性可通过 `php scripts/test-menu-manager-themes.php` 验证。
