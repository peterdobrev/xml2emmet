# xml2emmet

Convert XML/HTML markup ↔ Emmet abbreviations, with rules-based rewriting and an
interactive tree editor. PHP 8.2 backend (no framework — hand-rolled router on
`public/index.php`), MySQL 8 for users/rules/history, static SPA frontend in
`public/`.

## Documentation

- [`docs/PROJECT_STATUS.md`](docs/PROJECT_STATUS.md) — current branch, what works, what's next
- [`docs/design.md`](docs/design.md) — architecture, data flow, deployment notes
- [`docs/algorithms.md`](docs/algorithms.md) — Emmet/XML parser walkthroughs, rules engine, click-ops
- [`docs/emmet-grammar.md`](docs/emmet-grammar.md) — formal grammar accepted by `EmmetParser`
- [`docs/branch-http-api.md`](docs/branch-http-api.md) — HTTP API endpoints and routes
- [`docs/test-cases.md`](docs/test-cases.md) — canonical test fixtures

## Running the API

### Required env

| Var                       | Default          | Notes                              |
|---------------------------|------------------|------------------------------------|
| `XML2EMMET_DB_USER`       | (required)       | MySQL user                         |
| `XML2EMMET_DB_PASS`       | (required)       | MySQL password                     |
| `XML2EMMET_DB_HOST`       | `127.0.0.1`      |                                    |
| `XML2EMMET_DB_PORT`       | `3306`           |                                    |
| `XML2EMMET_DB_NAME`       | `xml2emmet`      |                                    |
| `XML2EMMET_SESSION_NAME`  | `xml2emmet_sid`  | PHP session cookie name            |
| `XML2EMMET_SECURE_COOKIE` | `0`              | Set `1` in HTTPS production        |
| `XML2EMMET_DEBUG`         | `0`              | Set `1` to include traces in 500s  |

### Run with Docker Compose

```bash
docker compose up
```

This brings up MySQL 8 on host port `3307` and the PHP app on port `8080`. The
`app` service runs `php bin/migrate.php` once before starting `php -S`. The
`mysql` service initialises an empty `xml2emmet_test` database; for a separate
runtime database you may want to add an init script under
`docker-entrypoint-initdb.d/`.

You'll need `composer install` on the host first — the bind-mounted `vendor/`
is what the app loads.

### One-time DB setup (bare metal)

```bash
mysql -u root -p -e "CREATE DATABASE xml2emmet CHARSET utf8mb4"
mysql -u root -p -e "CREATE DATABASE xml2emmet_test CHARSET utf8mb4"
XML2EMMET_DB_USER=root XML2EMMET_DB_PASS=secret php bin/migrate.php
XML2EMMET_DB_USER=root XML2EMMET_DB_PASS=secret XML2EMMET_DB_NAME=xml2emmet_test php bin/migrate.php
```

### Run the dev server (bare metal)

```bash
XML2EMMET_DB_USER=root XML2EMMET_DB_PASS=secret php -S 127.0.0.1:8080 -t public
```

### Run tests

Prerequisite: complete the One-time DB setup above. The HTTP integration tests
in `tests/Http/` spawn `php -S` per test class and require the test DB to
exist with the schema applied.

```bash
XML2EMMET_DB_USER=root XML2EMMET_DB_PASS=secret XML2EMMET_DB_NAME=xml2emmet_test \
  vendor/bin/phpunit
```
