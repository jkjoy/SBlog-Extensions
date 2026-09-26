# 第三方扩展登记

官方扩展源码位于仓库根目录的 `themes/` 和 `plugins/`。第三方作者可以继续在自己的仓库维护源码和 GitHub Release，只在此目录提交一个 JSON 文件。

文件名建议使用 `<type>-<slug>.json`，内容格式如下：

```json
{
  "type": "plugin",
  "slug": "example-plugin",
  "name": "Example Plugin",
  "version": "1.0.0",
  "author": "Example Author",
  "description": "Example description.",
  "homepage": "https://github.com/example/example-plugin",
  "requires": "1.13.6",
  "tested": "1.13.6",
  "download_url": "https://github.com/example/example-plugin/releases/download/v1.0.0/example-plugin-1.0.0.zip",
  "sha256": "64-lowercase-hex-characters"
}
```

要求：

- 下载地址必须使用 HTTPS，并指向不可变的版本文件。
- ZIP 内必须存在唯一的 `<slug>/theme.json` 或 `<slug>/plugin.json`。
- `version` 必须与 ZIP 内清单一致。
- 同一版本禁止替换下载地址或 SHA-256；修复包必须提升版本。
- 第三方条目不能与官方扩展的 `type:slug` 重复。
