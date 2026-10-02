# 支付 SDK 来源

本插件随包提供生产依赖，安装插件后不需要在服务器运行 Composer。

- 引用仓库：[jkjoy/pay](https://github.com/jkjoy/pay)，Composer 包名为 `yansongda/pay`。
- 固定版本：[`d1b354bc7808d00b75dd7b2038eb5359e90bafec`](https://github.com/jkjoy/pay/commit/d1b354bc7808d00b75dd7b2038eb5359e90bafec)。
- 完整依赖版本和源码引用记录在 `composer.lock`；生产源码保存在 `vendor/`。
- SDK 的 MIT 许可证保留在 `vendor/yansongda/pay/LICENSE`；各依赖的原始许可证随源码保留。
- 运行环境：PHP 8.2 及以上，以及 OpenSSL、JSON、BCMath、SimpleXML、LibXML 扩展。

打包依赖时禁用了 Composer 插件和包脚本。维护者可在插件目录使用以下命令按锁定版本重建生产依赖：

```sh
composer install --no-dev --no-scripts --no-plugins --prefer-dist
```

支付宝使用证书模式的电脑网站支付和手机网站支付；微信使用普通商户 API v3 H5 支付。支付通知由 SDK 验签，微信通知还会验证时间戳并解密。插件服务随后核对订单号、金额、币种、商户和应用身份，再授予阅读权限。
