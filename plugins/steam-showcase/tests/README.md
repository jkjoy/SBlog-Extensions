# Steam Showcase tests

Run from the blog directory:

```sh
php plugins/steam-showcase/tests/run.php
```

Requires PHP 8.0+ and `pdo_sqlite`. The runner is CLI-only. It creates an in-memory SQLite database and a random directory under the system temporary directory, then cleans that directory on exit. It does not load `index.php`, modify the installed blog, use actual credentials, or make network requests.

The suite checks configuration boundaries, preserving and clearing the API key, isolated secret storage, Steam response handling, caching and rendering. API cases use deterministic fixtures passed through the plugin's optional transport callback.
