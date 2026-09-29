# 评论增强

需要 SBlog 1.14.6 或更高版本，以使用评论身份、客户端元信息和审核状态挂钩。

为 SBlog 的公开评论补充以下信息：

- 邮箱等级：按同一访客邮箱在全站的已通过评论数实时计算。LV1 为 1-4 条、LV2 为 5-9 条、LV3 为 10-19 条、LV4 为 20-49 条、LV5 为 50-99 条、LV6 为 100 条及以上。登录管理员显示“博主”，不参与访客等级。
- IP 归属地：只公开国家/省级位置，不公开完整 IP。私网地址显示为“本地网络”。
- 客户端：使用带品牌识别色的内联 SVG 显示浏览器和操作系统系列，鼠标悬停或键盘聚焦时显示名称；不公开完整 User-Agent，也不展示精确版本。图标随插件输出，不会向图标 CDN 发起请求。

## IP 查询与隐私

IP 查询默认关闭。管理员可从“插件管理 -> 评论增强 -> 设置”选择本地 MMDB 或在线查询。

本地模式支持管理员上传不超过 128 MiB 的 MaxMind GeoLite2 或 GeoIP2 City/Country `.mmdb` 文件，并可查询数据库所包含的 IPv4 与 IPv6 地址。数据库使用随机文件名保存在站点 `data/comment-enhancer/` 目录中，不随插件打包，也不会产生第三方网络请求。切换到本地模式前必须先上传有效数据库。

生产部署必须禁止浏览器直接访问整个 `/data/` 目录，随机文件名不能替代该访问控制。使用 SBlog 自带的 PHP 内置服务器时，必须从主程序目录运行 `php -S 127.0.0.1:8000 router.php`，不要使用不带 `router.php` 的裸 `php -S` 命令。

在线模式启用后，新评论提交时会把公网 IP 通过 HTTPS 发送给 `ipwho.is`，只请求国家和省级位置。访客浏览公开页面绝不会触发第三方查询，外部服务不可用也不会阻止评论显示、审核或提交。

查询结果使用插件独立密钥生成的 HMAC 缓存在站点数据库中，成功结果最多保留 180 天，失败后 6 小时再重试。缓存不包含原始 IP，可随时从设置页清空；停用插件时也会自动清空并关闭查询。管理员可在设置页主动分批补全历史评论的归属地：在线模式每次最多处理 5 个公网 IP，本地模式每次最多处理 100 个。

SBlog 目前只记录服务器收到的 `REMOTE_ADDR`。如果站点位于 CDN 或反向代理后，请先在可信代理层把真实客户端地址传递为 Web 服务器的远端地址；插件不会信任可伪造的 `X-Forwarded-For`。

邮箱等级仅表示评论活跃度。访客邮箱和 User-Agent 都可以伪造，不能作为身份认证、权限或信誉判断依据。

## 图标与商标

部分品牌轮廓参考 [Simple Icons](https://simpleicons.org/)（CC0 1.0）和 [Font Awesome Free](https://fontawesome.com/)（CC BY 4.0）。所有产品名称和商标归其各自权利人所有；这些识别图标仅用于说明评论客户端，不表示品牌方对 SBlog 的认可或授权。

发行包包含官方 [MaxMind DB Reader for PHP](https://github.com/maxmind/MaxMind-DB-Reader-php) 的必要源码、Composer 元数据和 Apache-2.0 许可证，但不包含 GeoLite2 或 GeoIP2 数据库。仓库中的小型 MMDB fixture 只用于自动化测试，不会进入插件发行包。

## 主题兼容

内置 `default` 主题已支持此插件。自行重绘评论列表的自定义主题需要在评论作者昵称后依次输出：

```php
<?= render_comment_identity($comment, ['post' => $post, 'comments' => $comments]) ?>
<?= render_comment_meta($comment, ['post' => $post, 'comments' => $comments]) ?>
```

其中 `$comments` 应为当前页的完整评论数组，以便插件批量读取等级和归属地缓存。未调用对应接口的旧主题不会显示身份或评论增强信息，还需要根据主题布局适配 `.comment-enhancer-badge` 和 `.comment-enhancer-meta`。
