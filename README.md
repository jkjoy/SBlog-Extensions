# SBlog Extensions

Simple PHP Blog 的独立主题与插件仓库。官方扩展集中维护，第三方扩展通过 `registry/` 登记自己的不可变 Release 包。

## 目录

```text
themes/<slug>/       官方主题源码
plugins/<slug>/      官方插件源码
registry/*.json      第三方扩展登记
scripts/             校验、打包和目录生成工具
.github/workflows/   PR 校验与自动发布
```

## 发布规则

1. 修改官方扩展前，先提升其 `theme.json` 或 `plugin.json` 中的语义版本号。
2. Pull Request 会检查 JSON、PHP 语法、目录名、HTTPS 链接、文件数量、解压体积和版本变化。
3. 合并到 `main` 后，工作流只为新版本生成 `<type>-<slug>-<version>.zip`。
4. ZIP 以 `<slug>/` 为唯一根目录，并作为独立 GitHub Release Asset 发布。
5. 所有 Release Asset 上传并重新下载校验成功后，工作流才更新 `catalog` 分支。
6. 同一版本的源码或下载文件不得替换。发布错误必须提升版本后重新发布。

发布标签格式：

```text
theme-starter-v1.0.2
plugin-akismet-v1.0.2
```

目录地址：

```text
https://raw.githubusercontent.com/OWNER/REPOSITORY/catalog/catalog.json
```

SBlog 服务器可以通过环境变量切换商店：

```text
SBLOG_EXTENSION_STORE_URL=https://raw.githubusercontent.com/OWNER/REPOSITORY/catalog/catalog.json
```

## 本地验证

```bash
php scripts/test.php
php scripts/validate.php
php scripts/build.php --repository=OWNER/REPOSITORY
```

首次构建会为全部扩展生成发布包。已有 `catalog` 分支时，可验证增量发布：

```bash
php scripts/build.php \
  --repository=OWNER/REPOSITORY \
  --previous=/path/to/catalog.json
```

输出位于 `build/`：

- `packages/*.zip`：本次需要发布的新版本。
- `release-plan.json`：Release 标签、文件名、下载地址和 SHA-256。
- `catalog.json`：等待所有 Release 成功后发布的完整目录。

## 首次启用

1. 创建公开仓库，并将此目录推送为默认分支 `main`。
2. 在仓库 Actions 设置中允许工作流对仓库内容执行读写操作。
3. 运行一次 `Publish extensions`，确认 28 个 Release 和 `catalog` 分支都已生成。
4. 保护 `main`、`catalog` 和已发布标签，禁止强制推送和删除。
5. 确认目录 URL 可访问后，再修改 SBlog 默认目录地址或设置 `SBLOG_EXTENSION_STORE_URL`。

工作流使用并发锁；中途失败后可以安全重跑。已存在的 Release Asset 会先下载并核对 SHA-256，不会被静默覆盖。

## 下架扩展

不要直接删除扩展目录。先在 `store.config.json` 的 `retired` 数组中加入 `theme:<slug>` 或 `plugin:<slug>`，再删除源码。下架只会从商店目录移除扩展，不会删除用户站点中已经安装的文件。
