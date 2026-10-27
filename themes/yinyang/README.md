# yinyang

Simple PHP Blog 的 YinYang 主题，按 [joway/hugo-theme-yinyang](https://github.com/joway/hugo-theme-yinyang) 移植。

适用于 Simple PHP Blog 1.14.16 及以上版本。

参考版本：`3d5900f879122280b66a320df6d44dfaef559773`。原主题 CSS、Flexbox Grid、Bree Serif 与 Bungee Shade 字体均随主题本地提供，无需访问字体 CDN。

## 使用

将整个 `yinyang` 文件夹放入博客的 `themes/`，在「后台 → 主题管理」中预览或启用 **yinyang**。

站点名和副标题读取博客设置；导航默认显示归档、标签、链接、独立页面和 RSS。首页按年份分组，保留博客「每页文章数」设置及分页。分类在桌面显示边框标签，在手机隐藏。深浅色默认跟随系统，点击左上角月亮 / 太阳按钮切换，选择保存在当前浏览器。

`config.php` 支持覆盖页头标题、副标题、作者与导航链接。例如：

```php
'head_title' => 'Random Thoughts',
'description' => 'Log something useless, but interesting.',
'socials' => [
    ['name' => 'About', 'link' => '/about'],
    ['name' => 'GitHub', 'link' => 'https://github.com/yourname'],
],
```

链接支持完整 HTTP(S) 地址或站内绝对路径；博客部署在子目录时，站内路径需包含该子目录。留空 `socials` 使用默认导航。原主题页脚留白；设置 `show_footer => true` 可显示博客页脚文字，已设置的备案号始终显示。

## 兼容

支持文章、独立页面、归档、标签、分类、友链和错误页。正文、密码保护、回复后可见、评论及其插件身份信息继续由博客核心处理；主题仅调整详情页的标题、日期、作者与标签布局。无需 Hugo、Node.js、构建步骤或额外 PHP 扩展。

支持扩展仓库的「菜单管理」插件：开启接管后显示统一导航，外观切换和 RSS 入口保留；停用或取消接管后恢复主题原导航。

`style.css` 的前半段保留原主题样式，仅将字体地址改为主题相对路径；后半段适配博客自带的评论、媒体、门禁、分页和窄屏溢出。原主题以 MIT 协议分发，许可证见 `LICENSE`。
