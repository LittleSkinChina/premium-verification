# 新·正版验证

为拥有正版账号的用户提供验证、绑定。

集成了 Microsoft 账户绑定功能。

## 使用方法

请先在 Microsoft Entra ID（就是以前的 Azure Active Directory）中创建一个应用，生成 Client Secret，启用 ID 令牌，添加两条回调 URI（请将 example.com 换成你自己的域名）：

- 登录回调 URI：`https://example.com/microsoftoidc/callback`
- 正版验证回调 URI：`https://example.com/user/premium/callback`

然后在 .env 文件中添加以下条目：

- `MICROSOFT_CLIENT_ID`：应用的 Client ID
- `MICROSOFT_CLIENT_SECRET`：应用的 Client Secret
- `MICROSOFT_LOGIN_REDIRECT_URI`：上面添加的「登录回调 URI」
- `MICROSOFT_PREMIUM_REDIRECT_URI`：上面添加的「正版验证回调 URI」

## 未实现的功能

- 本地化（英文）
- 通过 Microsoft 账号快速登录时，如未绑定，引导用户注册或登录并自动绑定
- 角色强制更名时发送更名通知邮件

## 注意事项

本插件与官方版本的「正版验证」插件（mojang-verification）及「通过 Microsoft Live 登录」插件（oauth-microsoft-live）不兼容，且数据无法迁移。

用户绑定正版账号前要求先绑定 Microsoft 账户，如未绑定，则正版账号绑定完成后自动绑定所属的 Microsoft 账户。

正版账号绑定完成后不允许解绑，且绑定的 Microsoft 账户也不允许解绑。唯一的解绑方式是删除账号。

## 版权信息

本插件使用 MIT 协议进行授权。部分代码参考自官方版本的「正版验证」插件。

Copyright (c) 2016-present LittleSkin. All rights reserved.
