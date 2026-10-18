# Minimal AstrX application

A complete site built from the framework layers only (foundation + component):
no database, no accounts, no CMS, no modules, no JavaScript.

```sh
php -S 127.0.0.1:8000 examples/minimal/index.php
```

| Route | What it shows |
|---|---|
| `GET /` | a compiled template with escaped output, a loop and translated strings |
| `POST /` | POST/redirect/GET: the form result is a URL (303), not a re-submittable POST |
| `GET /hello.json?name=…` | a JSON response |
| anything else | a templated 404 |

`tests/framework_isolation_test.php` copies only the framework namespaces next
to this example and serves it from there, so CI fails if the framework starts
needing anything from the library, feature or application layers at runtime.
