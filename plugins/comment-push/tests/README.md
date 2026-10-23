# Offline checks

Run with PHP 8.0+ and the `pdo_sqlite` and `sodium` extensions:

```powershell
php plugins/comment-push/tests/run.php
```

The runner loads the actual plugin functions, supplies small stubs for core helpers, and creates a fresh `sqlite::memory:` database for each check. HTTP is injected as deterministic response fixtures. It does not bootstrap `index.php`, read or change the site's database/configuration, send messages, or call any live QQ/Weixin endpoint.

Coverage includes official QQ URLs and authentication, token reuse/expiry and one-time HTTP 401 renewal, error bodies returned with HTTP 200, asynchronous audit responses, recipient validation, and avoiding resend after ambiguous network failures. Webhook checks cover Ed25519 signing and exact raw request bytes, signed URL validation, required AppID, forged and malformed requests without state changes, body size and method limits, official event acknowledgements, and historical signature timestamps. Binding checks cover a single-use code with a ten-minute expiry, private and group recipients, mention handling, incorrect types/codes/identities, cancellation/replacement, credential changes, and preserving the notification enable switch. Comment tests check persisted comment/post IDs, status and administrator filtering, channel isolation, UTF-8 text and mention sanitation, and exclusion of email/IP data. Weixin checks cover official host validation, QR login and account storage, safe recipient synchronization/binding, message/session metadata, expiry, disconnect, and acknowledgement failures. Admin checks cover authentication, CSRF before every POST operation (including QQ binding), escaped rendering, and absent stored secrets.

Crypto fixtures use the [official QQ signing documentation](https://bot.q.qq.com/wiki/develop/api-v2/dev-prepare/interface-framework/sign.html), including its published public key and challenge signature. The page's event signature `865ad…ed002` does not verify against the literal body printed alongside it. The exact-body signature `2eb998…71102` was independently cross-checked using PHP Sodium and Node's native crypto; the printed, invalid signature is retained as a rejection fixture. No live documentation lookup is needed when running the checks.

The checks do not prove live account permissions, regional availability, delivery after platform review, or real HTTP/TLS operation. Complete those checks in the plugin settings with your own account and the explicit channel test buttons.
