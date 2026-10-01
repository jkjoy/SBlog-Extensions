# 评论增强

需要 SBlog 1.14.6 或更高版本，以使用评论身份、客户端元信息和审核状态挂钩。首次解压内置数据库需要 PHP zlib 扩展及可写的站点数据目录。

为 SBlog 的公开评论补充以下信息：

- 邮箱等级：按同一访客邮箱在全站的已通过评论数实时计算。LV1 为 1-4 条、LV2 为 5-9 条、LV3 为 10-19 条、LV4 为 20-49 条、LV5 为 50-99 条、LV6 为 100 条及以上。登录管理员显示“博主”，不参与访客等级。
- IP 归属地：只公开国家/省级位置，不公开完整 IP。私网地址显示为“本地网络”。
- 客户端：使用带品牌识别色的内联 SVG 显示浏览器和操作系统系列，鼠标悬停或键盘聚焦时显示名称；不公开完整 User-Agent，也不展示精确版本。图标随插件输出，不会向图标 CDN 发起请求。

## IP 查询与隐私

新安装默认使用本地模式，发行包内置 **DB-IP City Lite 2026-10** 正式数据库，无需注册、上传数据库或联网即可查询 IPv4 和 IPv6 归属地，中国大陆地址可显示数据库提供的省级位置。升级保留已有的关闭、在线查询和已上传本地数据库配置。管理员可从“插件管理 -> 评论增强 -> 设置”切换查询模式。

本地模式优先使用管理员上传的数据库；没有自定义数据库时使用插件内置库。管理员可以上传不超过 128 MiB 的 DB-IP、MaxMind GeoLite2 或 GeoIP2 City/Country `.mmdb` 文件，并可查询数据库所包含的 IPv4 与 IPv6 地址。上传的数据库使用随机文件名保存在站点 `data/comment-enhancer/` 目录中，不随插件打包；设置页可切回内置库。本地查询不会产生第三方网络请求。

内置数据库以官方原始压缩包 `resources/geo/dbip-city-lite.mmdb.gz` 随源码和安装包分发，各约 58 MiB。首次使用内置库时，插件在本机离线解压到受保护的 `DATA_DIR/comment-enhancer/builtin/dbip-city-lite-2026-10.mmdb`，校验大小和 SHA256 后原子保存，不修改数据库内容。解压需额外约 121.1 MiB 可写磁盘空间，完成前该空间由临时文件占用；重建已有缓存时，还需预留同等大小的临时空间。

DB-IP Lite 是覆盖和精度有限的免费版本，归属地仅作参考，部分地址可能缺少省级位置或与实际所在地不同。数据库是 2026-10 的静态快照，插件不会联网自动下载或更新。需要更新时，可从 [DB-IP 官方下载页](https://db-ip.com/db/download/ip-to-city-lite) 手动下载新版 MMDB，解压后通过设置页上传 `.mmdb` 文件，或升级到包含新版数据库的插件包。

生产部署必须禁止浏览器直接访问整个 `/data/` 目录，随机文件名不能替代该访问控制。使用 SBlog 自带的 PHP 内置服务器时，必须从主程序目录运行 `php -S 127.0.0.1:8000 router.php`，不要使用不带 `router.php` 的裸 `php -S` 命令。

在线模式启用后，新评论提交时会把公网 IP 通过 HTTPS 发送给 `ipwho.is`，只请求国家和省级位置。访客浏览公开页面绝不会触发第三方查询，外部服务不可用也不会阻止评论显示、审核或提交。

查询结果使用插件独立密钥生成的 HMAC 缓存在站点数据库中，成功结果最多保留 180 天，失败后 6 小时再重试。缓存不包含原始 IP，可随时从设置页清空；停用插件时也会自动清空并关闭查询。管理员可在设置页主动分批补全历史评论的归属地：在线模式每次最多处理 5 个公网 IP，本地模式每次最多处理 100 个。

SBlog 目前只记录服务器收到的 `REMOTE_ADDR`。如果站点位于 CDN 或反向代理后，请先在可信代理层把真实客户端地址传递为 Web 服务器的远端地址；插件不会信任可伪造的 `X-Forwarded-For`。

邮箱等级仅表示评论活跃度。访客邮箱和 User-Agent 都可以伪造，不能作为身份认证、权限或信誉判断依据。

## 图标与商标

部分品牌轮廓参考 [Simple Icons](https://simpleicons.org/)（CC0 1.0）和 [Font Awesome Free](https://fontawesome.com/)（CC BY 4.0）。所有产品名称和商标归其各自权利人所有；这些识别图标仅用于说明评论客户端，不表示品牌方对 SBlog 的认可或授权。

发行包包含官方 [MaxMind DB Reader for PHP](https://github.com/maxmind/MaxMind-DB-Reader-php) 的必要源码、Composer 元数据和 Apache-2.0 许可证。

内置 [DB-IP City Lite](https://db-ip.com/db/download/ip-to-city-lite) 数据库由 [DB-IP](https://db-ip.com/) 提供，按 [Creative Commons Attribution 4.0 International（CC BY 4.0）](https://creativecommons.org/licenses/by/4.0/) 原样分发，数据库许可独立于插件代码许可。数据来源、版本、校验信息和完整许可见 `resources/geo/`。根据数据提供方的署名要求，展示或使用该库查询结果的网页必须包含指向 DB-IP 的链接；插件在显示这些归属地时提供“IP Geolocation by DB-IP”署名。自定义主题输出该插件的评论元信息时，应保留该链接。

发行包不包含 MaxMind GeoLite2 或 GeoIP2 数据库。仓库中的小型 MMDB fixture 只用于自动化测试，不会进入插件发行包。

## 主题兼容

内置 `default` 主题已支持此插件。自行重绘评论列表的自定义主题需要在评论作者昵称后依次输出：

```php
<?= render_comment_identity($comment, ['post' => $post, 'comments' => $comments]) ?>
<?= render_comment_meta($comment, ['post' => $post, 'comments' => $comments]) ?>
```

其中 `$comments` 应为当前页的完整评论数组，以便插件批量读取等级和归属地缓存。未调用对应接口的旧主题不会显示身份或评论增强信息，还需要根据主题布局适配 `.comment-enhancer-badge` 和 `.comment-enhancer-meta`。
