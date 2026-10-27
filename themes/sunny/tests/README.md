# Sunny 评论验证

运行主题标签与公开布局数据检查：

```sh
php themes/sunny/tests/comments.php
```

提供本地 SBlog 核心源码可追加真实评论渲染集成检查：

```sh
php themes/sunny/tests/comments.php --core=/path/to/SBlog/index.php
```

集成检查从核心源码提取评论渲染函数，用虚构数据执行，不加载核心入口、数据库、配置或缓存。需要 PHP DOM 扩展。检查访客、管理员、空评论、关闭评论、错误、预选回复、审核通知和数量超过当前可见窗口等状态，验证核心表单字段、插件身份/元信息钩子、公开 JSON 字段及脚本转义。

浏览器中另外检查明暗模式、宽窄屏、回复与取消、键盘焦点、触屏回复按钮，以及多级回复与缺失父评论的显示。
