# 扩展验证脚本

`php scripts/test.php` 和 `php scripts/validate.php` 验证扩展仓库本身。需要运行真实主程序的主题集成测试时，请另行准备 Simple PHP Blog 源码，推荐使用当前发布版（主题原生设置最低需要 1.14.18）。本仓库不附带主程序。

Document 与 Retro Home 测试使用 `SBLOG_CORE_ROOT` 显式指定主程序源码目录，主题始终从本扩展仓库读取：

```sh
SBLOG_CORE_ROOT=/path/to/Simple-PHP-Blog php scripts/test-document.php
SBLOG_CORE_ROOT=/path/to/Simple-PHP-Blog php scripts/test-retro-home.php
```

需要 PHP CLI 的 `curl`、`pdo_sqlite`、`dom` 扩展，以及启动本地 PHP HTTP 服务的权限。测试只复制 `index.php`、`router.php`、`favicon.png`、`logo.png` 和 `assets/`，新建临时数据库与会话目录，不读取或修改源码目录中的已安装数据库、配置和缓存。成功后自动删除临时夹具，失败时保留以便检查。

两者均支持 `--keep` 保留夹具、`--prepare /tmp/new-fixture` 生成可手动预览的夹具，以及 `--sync /tmp/existing-fixture` 刷新源码。`--prepare` 要求目标不存在，`--sync` 仅接受对应测试创建且标记有效的夹具。Document 另支持 `SBLOG_DOCUMENT_CORE_FILE=/path/to/other-index.php` 替换测试核心入口，用于兼容性验证；其余核心资源仍由 `SBLOG_CORE_ROOT` 提供。

已有 H-Siren 测试仅提取真实核心函数，使用内存数据库，通过 `SBLOG_CORE_INDEX` 指定入口文件：

```sh
SBLOG_CORE_INDEX=/path/to/Simple-PHP-Blog/index.php php scripts/test-h-siren.php
```
