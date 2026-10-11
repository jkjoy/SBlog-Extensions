# 静态页面缓存

将匿名访客访问的公开文章和独立页面缓存为静态 HTML，并在文章或评论变化时使缓存失效。

在文章编辑器的“更多设置”中勾选“跳过缓存”，可让当前文章或独立页面始终动态生成。保存草稿或发布时会保存此选项；取消勾选后保存即可恢复缓存。密码保护和回复可见内容会自动绕过缓存。

插件通过核心的 `editor_settings_html` filter 追加编辑选项。对于没有此 filter 的旧核心，插件会优先将选项放入已有的“更多设置”；更早的核心仍显示在正文编辑器下方。最低支持 SBlog 1.14.8。

本地验证：

```bash
php -l plugins/static-page-cache/plugin.php
php scripts/test-static-page-cache.php
php scripts/validate.php
```

发布时需同时更新 `plugin.json` 与 `SBLOG_STATIC_PAGE_CACHE_VERSION`，并通过扩展仓库的自动发布流程生成新版本安装包。同一版本的安装包不可替换。
