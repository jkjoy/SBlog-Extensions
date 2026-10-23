# Offline checks

Run with PHP 8.0+ and the `pdo_sqlite` extension:

```powershell
php plugins/comment-push/tests/run.php
```

The runner loads the actual plugin functions, supplies small stubs for core helpers, and creates a fresh `sqlite::memory:` database for each check. HTTP is injected as deterministic response fixtures. It does not bootstrap `index.php`, read or change the site's database/configuration, send messages, or call any live QQ/Weixin endpoint.

Coverage includes official QQ URLs and authentication, token reuse/expiry and one-time HTTP 401 renewal, error bodies returned with HTTP 200, asynchronous audit responses, recipient validation, and avoiding resend after ambiguous network failures. Comment tests check persisted comment/post IDs, status and administrator filtering, channel isolation, UTF-8 text and mention sanitation, and exclusion of email/IP data. Weixin checks cover official host validation, QR login and account storage, safe recipient synchronization/binding, message/session metadata, expiry, disconnect, and acknowledgement failures. Admin checks cover authentication, CSRF before every POST operation, escaped rendering, and absent stored secrets.

The checks do not prove live account permissions, regional availability, delivery after platform review, or real HTTP/TLS operation. Complete those checks in the plugin settings with your own account and the explicit channel test buttons.
