# 第三方 SNS 登录

为 SBlog 已有管理员提供 GitHub、Google、LINUX DO 和 QQ 登录。管理员先用后台密码登录，在插件设置中绑定第三方账号，此后即可从登录页授权登录。可以通过插件过滤器接入更多 OAuth 2.0 服务。

SBlog 当前的 `users` 表没有访客角色，所有用户都能进入后台，因此本插件仅允许登录已绑定的管理员，不开放第三方注册，不会自动创建用户，也不会通过邮箱匹配或合并账号。

## 安装与使用

1. 安装插件并在“后台 → 插件管理”启用“第三方登录”。
2. 在“站点设置”填写完整的 HTTPS 站点地址，例如 `https://blog.example.com`；子目录部署填写 `https://example.com/blog`。
3. 打开“第三方登录 → 设置”，在各服务商创建应用，将页面显示的完整“授权回调地址”填入应用设置，再填写 Client ID 和 Client Secret 并启用对应服务。QQ 使用 App ID 和 App Key。
4. 保存配置后，在“我的账号绑定”输入当前后台密码，点击“验证密码并前往授权”。授权账号会绑定到当前管理员。
5. 退出后台，在登录页选择已绑定的服务。未绑定的第三方账号无法登录。

回调使用查询路由，无需增加伪静态规则。请直接复制插件设置中的地址，保留全部查询参数。例如：

```text
https://blog.example.com/index.php?a=sns_login_callback&provider=github
https://blog.example.com/index.php?a=sns_login_callback&provider=google
https://blog.example.com/index.php?a=sns_login_callback&provider=linuxdo
https://blog.example.com/index.php?a=sns_login_callback&provider=qq
```

第三方授权需要浏览器允许站点 Session Cookie，服务器能通过 HTTPS 访问对应服务商。插件使用 PHP cURL；没有 cURL 时可使用启用 `allow_url_fopen` 的 HTTPS streams，不依赖 Composer。站点地址必须与用户实际访问的域名、协议和部署目录一致。

## 各平台应用配置

| 平台 | 创建应用 | 配置要点 |
| --- | --- | --- |
| GitHub | [Developer settings → OAuth Apps](https://github.com/settings/developers) | 创建 OAuth App，将 Authorization callback URL 设置为插件显示的 GitHub 回调地址。 |
| Google | [Google Auth Platform](https://console.cloud.google.com/auth/overview) | 配置应用品牌与受众，创建 Web application OAuth 客户端，将 Google 回调地址加入 Authorized redirect URIs；测试状态需添加测试用户。 |
| LINUX DO | [LINUX DO Connect](https://connect.linux.do/) | 创建 OAuth 应用，填写插件显示的 LINUX DO 回调地址。应用申请与账号可用条件以 Connect 平台要求为准。 |
| QQ | [QQ 互联](https://connect.qq.com/) | 创建网站应用并按平台要求审核，填写网站域名与回调地址。QQ OpenID 属于具体 App ID，必须使用同一应用完成绑定和登录。 |

服务商可能要求应用审核或域名验证。只配置平台所需的登录权限即可，插件使用服务商返回的稳定账号 ID 识别账号，不依赖邮箱，也不需要保存邮箱。

## 管理与安全

- 绑定和解绑需要已登录的管理员、CSRF 校验以及当前后台密码。第三方账号已经绑定其他管理员时不会转移绑定。
- 账号身份由 `provider + client_id + subject` 标识。更换 Client ID 后，旧绑定不能用于新应用登录；设置页会保留旧应用记录供管理员解绑，再为新应用重新绑定。
- 授权使用 Session 中的一次性 `state`，在支持的平台使用 PKCE。授权回调只接受当前浏览器发起且未过期的授权流程。
- Access Token 只在服务器内用于获取身份，不持久化、不传入页面或浏览器。账号绑定只保存服务商、应用 ID、稳定账号 ID、显示名称及时间。
- Client Secret 保存在插件自己的数据库配置表中，设置页不回显；输入留空保留原密钥，勾选“清除已保存的密钥”可删除。密钥不是加密保险箱，请按站点数据库备份同等级保护数据库文件。
- 授权地址来自已配置的 HTTPS 站点地址，避免用访客提供的 Host 生成回调。登录、回调和设置页面使用私有且不缓存的响应。
- 原用户名和密码登录始终可用。停用插件只关闭第三方入口，保留配置和绑定；第三方不可用时可用后台密码登录并解绑。
- 插件启用时，解除绑定、停用某个平台或更改应用凭据，会使使用该绑定建立的后台会话在下一次请求失效。直接停用整个插件后，此检查不会运行，已有后台会话遵循 SBlog 自身的退出与过期规则。

## 扩展其他平台

自定义服务商通过 `sns_login_providers` 过滤器加入注册表，提供应用名称、HTTPS 授权/令牌/身份端点、作用域、PKCE 支持及身份适配器。身份适配器将服务商响应转换为稳定 `subject` 与显示 `name`；应使用服务商签发的不可变账号 ID，不要以邮箱、昵称或客户端输入作为身份。

新的服务商描述符必须遵循插件 `includes/providers.php` 的注册表字段与适配器约定。扩展代码属于服务器可信代码；使用固定 HTTPS 端点，按照该服务的官方 OAuth 文档处理令牌与身份，保持 `provider + client_id + subject` 隔离，并为实际配置的应用验证完整授权流程。

```php
add_plugin_filter('sns_login_providers', static function (array $providers): array {
    $providers['example'] = [
        'name' => 'Example',
        'authorize_url' => 'https://accounts.example.com/oauth/authorize',
        'token_url' => 'https://accounts.example.com/oauth/token',
        'profile_url' => 'https://accounts.example.com/api/me',
        'scope' => 'profile',
        'pkce' => true,
        'token_auth' => 'body', // 服务商要求 HTTP Basic 时使用 basic。
        'adapter' => 'example_sns_identity',
    ];
    return $providers;
});
```

内置四个平台的描述符保持固定，扩展过滤器仅追加新平台。适配器签名与请求上下文见 `sns_fetch_identity()`；返回身份将经过插件的统一校验。

## 排查问题

- 看不到登录按钮：检查插件已启用、服务已启用、Client ID / Secret 已填写，以及站点 HTTPS 地址有效。
- 回调地址不匹配：复制设置页的完整地址，检查子目录和查询参数，确认服务商控制台已经保存。
- 授权后提示尚未绑定：先用后台密码登录，在插件设置中为该管理员完成绑定。
- 授权状态无效或已过期：允许站点 Cookie，从登录页重新发起授权；不要复用旧回调地址。
- 更换应用后无法登录：用原后台密码登录，解除旧应用绑定，再绑定新应用。
- 服务器请求失败：检查 DNS、出站 HTTPS、CA 证书、PHP cURL 或 HTTPS streams，以及服务商应用状态。

本地安全回归测试不需要真实 Client Secret；上线前仍需使用自己的应用验证各平台授权回调。
