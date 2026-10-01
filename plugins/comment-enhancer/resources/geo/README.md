# 内置 IP 归属地数据库

- 随包文件：`dbip-city-lite.mmdb.gz`（官方原始 gzip）
- 解压内容：`dbip-city-lite.mmdb`；运行时保存为 `DATA_DIR/comment-enhancer/builtin/dbip-city-lite-2026-10.mmdb`
- 数据集：[DB-IP City Lite](https://db-ip.com/db/download/ip-to-city-lite)
- 提供方：[DB-IP](https://db-ip.com/)
- 版本：2026-10（每月发布，本插件内置静态快照）
- 格式：MaxMind DB 2.0 / MMDB，支持 IPv4 与 IPv6
- 未压缩大小：126,998,165 字节（约 121.1 MiB）
- 压缩大小：60,193,321 字节（约 57.4 MiB，插件源码和安装包各约 58 MiB）
- 来源：[2026-10 官方压缩包](https://download.db-ip.com/free/dbip-city-lite-2026-10.mmdb.gz)
- 许可：[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)，完整文本见 `LICENSE.txt`

随包文件来自 DB-IP 官方发布包，压缩包和数据库内容均未作修改，仅统一文件名。来源和校验信息保存在 `provenance.json`：`file`、`size_bytes`、`sha256` 和官方 MD5/SHA1 指解压后的 MMDB 内容；`compressed_file`、`compressed_size_bytes`、`compressed_sha256` 指随包 gzip 文件。数据许可独立于插件代码和 MMDB 读取器的许可。

DB-IP City Lite 是商业库的免费子集，覆盖和精度有限。数据库提供国家、行政区和城市等字段，评论增强只公开国家或省级位置，不显示城市、坐标或完整 IP。中国大陆地址可以显示数据中可用的省级位置，部分地址可能没有省级数据，查询结果仅作参考。

## 署名

DB-IP 要求 Web 应用在展示或使用数据库查询结果的页面链接至 DB-IP。插件输出的署名为：

```html
<a href="https://db-ip.com">IP Geolocation by DB-IP</a>
```

自定义主题应保留该链接。再分发时请保留本目录中的来源、版本、许可文本和署名说明；如果修改数据库，应注明修改。

## 使用与更新

新安装默认使用本地模式，无需下载或上传数据库，也不会向第三方发送 IP。升级保留已有的关闭、在线查询或上传数据库配置。上传数据库覆盖内置库，管理员可在设置页切回内置库。

首次使用内置库时，插件在本机离线流式解压到上述受保护的数据目录，校验数据库大小与 SHA256 后原子保存，以后复用解压后的文件。首次解压需要额外约 121.1 MiB 可写磁盘空间，完成前该空间由临时文件占用；重建已有缓存时还需预留约 121.1 MiB 临时空间。解压与校验均不会联网，也不改变数据库内容。

插件不会自动联网更新。管理员可从 [官方页面](https://db-ip.com/db/download/ip-to-city-lite) 手动下载较新的 City Lite `.mmdb.gz`，解压为 `.mmdb` 后在设置页上传，或升级到包含较新快照的插件发行包。上传数据库须符合插件 128 MiB 上限和服务器的上传限制。
