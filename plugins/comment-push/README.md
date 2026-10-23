# QQ / 微信评论推送

在 SBlog 后台启用此插件，即可通过官方 QQ Bot 和微信 iLink 通道向站长发送新评论通知。微信通道参考腾讯官方 OpenClaw 微信插件移植为 PHP，不需要安装或运行 OpenClaw。

## 安装

1. 将整个 `comment-push` 文件夹放入站点的 `plugins/` 目录。
2. 进入「后台 → 插件管理」，启用「QQ / 微信评论推送」，打开「设置」。
3. 配置需要使用的渠道，保存后分别点击测试按钮。

需要 PHP 8.0+、cURL 和站点原有的 PDO SQLite 扩展，服务器须能通过 HTTPS 访问腾讯官方接口。配置保存在博客数据库的 `plugin_comment_push` 表中，凭证不会写入核心设置缓存，也不会回填到密码框。

## 官方 QQ Bot

1. 在 [QQ 开放平台](https://q.qq.com/) 创建机器人，取得 **AppID** 与 **AppSecret**，并开通对应场景权限。
2. 选择单聊、群聊或频道，填写接收 ID：
   - 单聊：该机器人的 `user_openid`，可从 `C2C_MESSAGE_CREATE` 事件的 `author.user_openid` 获取。
   - 群聊：该机器人的 `group_openid`，可从群消息事件获取。
   - 频道：目标子频道的 `channel_id`。
3. 单聊需要添加机器人好友并允许主动消息；群聊需要机器人已入群，且群主在最新版 QQ 中开启主动推送权限。接收 ID 不能填写真实 QQ 号码或群号码，不同 AppID 的 OpenID 不通用。
4. 保存设置并发送测试。频道 API 要求机器人保持 WebSocket 在线，需要已有的机器人程序维持连接，本插件只负责 HTTP 发送。平台也可能根据账号状态拒绝离线机器人发送其他场景的消息，以后台错误提示为准。

本插件使用 `https://api.bot.qq.com`，以 `QQBot` 方式认证；访问凭证缓存到到期前 60 秒，遇到 HTTP 401 时重新获取凭证并仅重试一次。主动通知不携带伪造的 `msg_id` / `event_id`。

QQ 主动消息受平台权限、接收方设置、审核及频率限制。当前官方文档列出的单聊/群聊上限为每个目标每日 1000 条、每分钟 20 条，实际以平台为准。如果文章域名未获平台允许，可关闭「附带文章链接」。平台审核中的消息会记录为待审核，不会显示为发送成功。

参考：[访问凭证](https://bot.q.qq.com/wiki/develop/api-v2/dev-prepare/access-token.html)、[消息规则](https://bot.q.qq.com/wiki/develop/api-v2/server-inter/message/overview.html)、[单聊发送](https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_users_user_openid_messages.post.html)、[群聊发送](https://bot.q.qq.com/wiki/develop/api-v2/autogen/api/v2_groups_group_openid_messages.post.html)。

## 微信（来自 OpenClaw 微信通道）

1. 在插件设置页发起微信登录，使用微信扫描页面显示的二维码，并按提示确认。如果微信要求验证码，在后台输入后继续验证。
2. 登录后，向微信中的机器人发送一条消息，例如「绑定通知」，然后在后台同步会话并绑定接收人。
3. 启用微信通知并保存，点击微信测试按钮。

微信使用腾讯官方 iLink HTTPS JSON 接口，移植来源为 [Tencent/openclaw-weixin](https://github.com/Tencent/openclaw-weixin)（MIT 许可），协议见 [官方中文协议文档](https://github.com/Tencent/openclaw-weixin/blob/main/docs/protocol_zh_CN.md)。许可证和移植说明见本目录的 `THIRD_PARTY_NOTICES.md`。二维码在浏览器本地生成，二维码内容和登录凭证不会上传给第三方二维码服务。

微信消息依赖已建立的会话以及平台提供的上下文。若平台提示会话失效，请重新扫码；若会话上下文不可用，请给机器人再发一条消息并同步。此通道不能保证任意时间都能主动投递，微信平台的规则和上下文有效性会影响发送结果。后台的「接口已接受」仅表示接口返回成功，不代表接收设备已经阅读或收到消息。

## 评论通知行为

- 通过核心 `comment_created` 钩子在评论写入成功后发送，适用于文章及独立页面；支持已发布和待审核评论。
- 默认跳过登录管理员发布的评论，可在设置中开启。垃圾评论及评论审核操作不触发新评论通知。
- 两个渠道可单独或同时启用；一个渠道失败后仍继续处理另一个渠道。
- 消息包含站点、文章、状态、评论者和最多 400 字的评论摘要，可选文章链接；不附带邮箱、IP 或 User-Agent。
- 与核心「新评论显示后台提醒」开关独立，不修改原有邮件通知。
- 评论请求内进行有时间限制的同步发送，网络故障可能增加等待时间；发送失败不会回滚评论。没有后台队列，也不会对超时或一般错误自动重发，避免重复通知。
- 测试、登录、同步和解绑均要求管理员登录与 CSRF 校验。测试使用已保存的配置，不会自动发送真实评论。
- 最近结果仅保留每个渠道的一条记录，不保存评论正文或原始 API 响应。停用插件后停止发送，微信解绑会清除相应凭证及会话。

请按主程序要求禁止直接访问 `plugins/` 中的 PHP、JSON 和 Markdown 文件，并保护站点数据库及备份。

## 离线验证

```console
php plugins/comment-push/tests/run.php
```

测试使用内存数据库和模拟接口，不会读取已安装站点的数据库，也不会向 QQ 或微信发送消息。
