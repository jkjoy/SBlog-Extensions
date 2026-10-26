# 文章点赞

启用后为主题提供点赞 API 和按钮资源，不自动向文章或页面插入按钮，无设置页。需要 SBlog 1.14.13 或更新版本。

## 主题集成

列表先批量获取状态，避免每张卡片单独查询：

```php
$likeStates = function_exists('article_likes_states')
    ? article_likes_states(array_column($posts, 'id'))
    : [];

foreach ($posts as $post) {
    if (function_exists('article_likes_button')) {
        echo article_likes_button((int)$post['id'], $likeStates[(int)$post['id']] ?? null);
    }
}
```

文章详情可直接调用 `article_likes_button((int)$post['id'])`。同一篇文章在当前页面的多个按钮会同步数量、文字及 `aria-pressed`。主题需保留 `head` 与 `body_close` 钩子以加载资源，可覆盖 `.article-likes-button` 的基础样式。

`article_likes_states(array $postIds): array` 返回 `[文章 ID => ['count' => int, 'liked' => bool]]`。忽略非正整数输入并去重，单次查询最多处理 500 个 ID。不存在、未公开、定时文章及独立页面返回零数量和未点赞状态；只有已到发布时间的公开文章读取真实记录。

`article_likes_button(int $postId, ?array $state = null): string` 返回按钮 HTML，非正数 ID 返回空字符串。传入的状态应来自批量 API；省略时自动读取。按钮含 `data-article-like`、`data-post-id`、`data-like-endpoint`、`data-like-csrf`，子元素为 `data-like-label` 与 `data-like-count`。前端成功后派发 `article-likes:updated` 事件，`detail` 为 `{postId, count, liked}`。

## 存储与请求

直接复用核心 `post_likes` 表和 `like_post_for_visitor()`，保留已存在的点赞记录。按核心 `client_ip_hash()` 去重：同一 IP 对同一篇文章只能点赞一次，重复请求不会增加数量，不支持取消。清除浏览器缓存或更换会话不会移除已保存的点赞，也不使用 `localStorage` 计数。共享出口 IP 的访客会共用该 IP 的点赞状态；改变 IP 可能形成新的访客记录。

按钮发送同源 POST 到 `index.php?a=article_likes_vote`，字段为 `post_id` 与会话 `csrf_token`。返回 JSON：

```json
{"ok":true,"post_id":42,"count":7,"liked":true,"created":false}
```

非 POST 返回 405；无效 CSRF 返回 422；不存在、草稿、私密、未到发布时间文章和独立页面返回 404；保存异常返回 500。错误格式为 `{"ok":false,"error":"说明"}`。密码保护的已发布文章沿用核心规则，可以点赞。插件不会通过此接口返回文章正文。

含按钮的页面带私有、不缓存响应头，并绕过已安装的 `static-page-cache` 插件缓存计划，以免不同访客共享点赞状态。停用或移除插件不会删除点赞记录；主题使用 `function_exists` 检查后即可正常退化。

## 图标许可

按钮心形 SVG 来自 [Lucide](https://lucide.dev/)，采用 ISC 许可。官方完整许可及其中保留的 Feather MIT 说明见随包的 `LICENSE-Lucide.txt`。

## 回归测试

```text
php plugins/article-likes/tests/run.php
```

在独立扩展仓库运行时，使用 `SBLOG_CORE_FILE` 指向一份 SBlog 1.14.13 或更新版本的核心 `index.php` 源码，例如：

```sh
SBLOG_CORE_FILE=/path/to/sblog/index.php php plugins/article-likes/tests/run.php
```

PowerShell：

```powershell
$env:SBLOG_CORE_FILE = 'D:\path\to\sblog\index.php'
php plugins/article-likes/tests/run.php
```

未设置环境变量时，默认读取插件所在博客根目录的 `index.php`。该设置会由测试子进程继承，仅用于读取、提取核心函数及表结构，不会执行核心入口、连接该站点数据库或修改该文件。

测试只使用临时 SQLite 数据库和核心函数，不加载正式站点入口，不读取或写入正式数据。覆盖记录兼容、重复请求持久化、访客 IP 去重、批量状态、按钮、请求方式、CSRF、文章公开状态与错误响应。
