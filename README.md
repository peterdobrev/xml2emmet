# xml2emmet

## Running the API

### Required env

| Var                       | Default       | Notes                              |
|---------------------------|---------------|------------------------------------|
| `XML2EMMET_DB_USER`       | (required)    | MySQL user                         |
| `XML2EMMET_DB_PASS`       | (required)    | MySQL password                     |
| `XML2EMMET_DB_HOST`       | `127.0.0.1`   |                                    |
| `XML2EMMET_DB_PORT`       | `3306`        |                                    |
| `XML2EMMET_DB_NAME`       | `xml2emmet`   |                                    |
| `XML2EMMET_SECURE_COOKIE` | `0`           | Set `1` in HTTPS production        |
| `XML2EMMET_DEBUG`         | `0`           | Set `1` to include traces in 500s  |

### One-time DB setup

```bash
mysql -u root -p -e "CREATE DATABASE xml2emmet CHARSET utf8mb4"
mysql -u root -p -e "CREATE DATABASE xml2emmet_test CHARSET utf8mb4"
XML2EMMET_DB_USER=root XML2EMMET_DB_PASS=secret php bin/migrate.php
XML2EMMET_DB_USER=root XML2EMMET_DB_PASS=secret XML2EMMET_DB_NAME=xml2emmet_test php bin/migrate.php
```

### Run the dev server

```bash
XML2EMMET_DB_USER=root XML2EMMET_DB_PASS=secret php -S 127.0.0.1:8080 -t public
```

### Run tests

```bash
XML2EMMET_DB_USER=root XML2EMMET_DB_PASS=secret XML2EMMET_DB_NAME=xml2emmet_test \
  vendor/bin/phpunit
```

The HTTP integration tests in `tests/Http/` spawn `php -S` per test class and require the test DB to exist with the schema applied.
