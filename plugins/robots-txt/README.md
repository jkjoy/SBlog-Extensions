# robots.txt 生成器

启用后，在「后台 → 插件管理 → robots.txt 生成器 → 设置」配置规则。默认允许抓取公开内容，排除后台、登录、数据、缓存和安装更新入口，并自动加入本站 Sitemap。

## 设置

- **自定义规则**：每行一个 User-agent，共用一组 Allow / Disallow 路径。`*` 表示所有爬虫。默认禁止路径会包含站点安装目录前缀。
- **允许全部抓取 / 禁止全部抓取**：使用 `User-agent: *`，分别生成空 `Disallow:` 或 `Disallow: /`，忽略已填写的爬虫名称和路径。
- **路径**：从域名根目录开始，以 `/` 开头，每行一个，支持 `*` 和 `$`。例如 `/private/`、`/*?preview=`；不要填写完整网址、注释或空白，空格请使用 `%20`。
- **Sitemap**：可自动引用核心 Sitemap，也可每行填写一个额外 HTTP / HTTPS 地址。自动地址会适应站点地址、伪静态和子目录设置。关闭自动选项后，额外 Sitemap 仍会输出。

保存后动态输出立即更新。设置页显示当前已保存内容，并提供访问、纯文本预览和下载按钮。输入错误时保留表单内容，当前生效规则不变。

## 访问与部署

域名根目录安装时，访问 `/robots.txt`。服务器需要将不存在的路径转发到 SBlog 的 `index.php`，可使用核心提供的 Apache / Nginx 伪静态配置；「启用伪静态链接」选项不影响插件匹配这个路径。无需伪静态的文本入口为 `/index.php?a=robots_txt`，下载入口为 `/index.php?a=robots_txt&download=1`。

搜索引擎只读取域名根目录的 `/robots.txt`。如果 SBlog 安装在 `/blog/`，`/blog/robots.txt` 可用于检查内容，但必须将下载文件部署到域名根目录，或配置服务器将根 `/robots.txt` 转发到 `/blog/index.php?a=robots_txt`。路径规则必须保留 `/blog/` 前缀；「禁止全部抓取」模式的 `/` 会禁止整个域名。

实体 `robots.txt` 会由 Web 服务器优先返回。已有文件时，设置页会提示备份移除或用下载内容更新。插件不写入、不覆盖站点文件；停用插件后动态入口失效，手动部署的实体文件需自行更新。

robots.txt 是搜索引擎抓取规则，访问权限仍由站点自身控制。

## 验证

```sh
php scripts/test-robots-txt.php
php scripts/validate.php
```
