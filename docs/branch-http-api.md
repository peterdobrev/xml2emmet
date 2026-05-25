# Branch: `feat/http-api` — HTTP API Layer

**Spec:** `docs/superpowers/specs/2026-05-25-http-api-design.md`
**Tests:** 172 passing, 0 failures (requires MySQL — see Running Tests below)
**Branch base:** `origin/master` (merged from `feat/engine-core`)

---

## What this branch does

This branch adds a complete JSON HTTP API on top of the existing xml2emmet engine. The engine itself (parsers, emitter, rule matching, click-ops, stats) was already on `master`. This branch wires it to HTTP.

---

## What was built

### Engine changes (3 small refactors required by the API)

| File | Change |
|------|--------|
| `src/ClickOpError.php` | Added a structured `code` string field (via `__get` magic) so HTTP handlers can map click-op failures to error envelope codes without parsing the message string |
| `src/ClickOps.php` | Split the old `swap {path, with}` (tag rename) into two distinct ops: `swap {path}` (sibling swap) and `rename {path, with}` (tag rename) — matching the spec's six-operation contract |
| `src/Stats.php` | Extended `Stats::compute()` to produce `classCounts` (per-class frequency map) and `depthHistogram` (per-depth node count) in addition to the existing fields, all in a single tree walk |
| `src/Stats/CssClassCounter.php` | New ~20-line regex tokenizer that scans CSS for `.classname` patterns and counts occurrences — used by `POST /api/stats` with `kind: "css"` |

### Persistence layer (`src/Db/`)

| File | Responsibility |
|------|---------------|
| `src/schema/001_init.sql` | Three tables: `users`, `rules`, `transformations`. FK CASCADE on both child tables. JSON columns for `settings` and `rule_ids` on transformations. `MEDIUMTEXT` for input/output. Composite index `idx_tx_user_created (user_id, created_at DESC)` |
| `bin/migrate.php` | Reads `src/schema/*.sql` in filename order, tracks applied files in `schema_migrations`, skips already-applied. Reads DB credentials from env |
| `src/Db/Db.php` | PDO factory — connects with `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, prepared statements |
| `src/Db/UserStore.php` | `create(username, password_hash)`, `findByUsername`, `findById` |
| `src/Db/RuleStore.php` | Full CRUD scoped by `user_id`. Includes `findUnownedIds(userId, ids[])` for batch cross-user validation in the transform endpoint |
| `src/Db/HistoryStore.php` | `insert(...)`, `listForUser(userId, page, perPage)` (returns `{items, page, per_page, total}`), `findOwned(userId, id)` |

### Config (`src/Config.php`, `config/config.php`)

Reads env vars at boot. Two are required (`XML2EMMET_DB_USER`, `XML2EMMET_DB_PASS`), rest have defaults. Throws `RuntimeException` on missing required vars — this bubbles into the front controller's catch block and returns a 500 JSON response.

### HTTP layer (`src/Http/`)

| File | Responsibility |
|------|---------------|
| `Request.php` | Immutable value object. Parses method, path, query string, headers. Auto-decodes JSON body when `Content-Type: application/json` — handles the case where body is passed directly to constructor or read from `php://input` |
| `Response.php` | Immutable value object. `Response::json(status, data)` and `Response::error(status, code, message, details)` factories. `send()` emits headers + body |
| `Json.php` | `encode()` with `JSON_UNESCAPED_SLASHES\|JSON_UNESCAPED_UNICODE`. `decode()` returning null on failure |
| `Router.php` | Method + path pattern → handler. Supports `{param}` segments. `add(..., gate: true)` blocks unauthenticated users with 401. Returns 404 for unknown paths, 405 for known path/wrong method |
| `Session.php` | Static: `start(Config)` sets cookie params before `session_start()`. `login(userId)` calls `session_regenerate_id(true)`. `logout()` clears cookie and destroys session. `userId()` returns `int\|null` |
| `Validation.php` | Field validators: `requireString`, `requireInt`, `requireEnum`, `requireMatch`, `optionalBool`. Accumulates errors, checked with `ok()` / `errors()` |
| `NodeJson.php` | `toArray(Node): array` — wire format `{tag, attrs, text, children}`, **excludes `appliedRules`**. `fromArray(array): Node` for round-trip |

### Handlers (`src/Http/Handlers/`)

| Handler | Endpoints | Notes |
|---------|-----------|-------|
| `AuthHandler` | `POST /api/auth/register`, `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me` | Username regex `^[A-Za-z0-9_]{3,64}$`. Password ≥ 8 chars. Login message intentionally vague: `"Username or password is incorrect."`. Register returns 409 on duplicate username |
| `TransformHandler` | `POST /api/transform` | Pipeline: parse → apply rules (as `Rule` objects) → apply click-ops (atomic, first failure aborts) → post-filter (show_text/show_attrs/show_attr_values, only applied to Emmet output) → emit. Optional `save: true` inserts history row and returns `saved_id` |
| `RulesHandler` | `GET/POST /api/rules`, `PUT/DELETE /api/rules/{id}` | Validates both `pattern` and `replacement` parse as valid Emmet on create/update. Returns `details.field` on parse error. Cross-user access returns 404 |
| `HistoryHandler` | `GET /api/history`, `GET /api/history/{id}` | Pagination via `page`/`per_page` query params, `per_page` clamped to 1–100, default 50. Cross-user detail returns 404 |
| `StatsHandler` | `POST /api/stats` | `kind: "html"` runs `Stats::compute()` on the parsed tree. `kind: "css"` runs `CssClassCounter::count()`. `top_classes` sorted by count desc, name asc, capped at 100 |

### Front controller (`public/index.php`)

Replaces the old PHP-rendered demo page. On every request:

1. Load config
2. Check `CONTENT_LENGTH` — reject > 2 MB with `413 payload_too_large` before any further work
3. Start session
4. Build `Request` from globals
5. Connect PDO, instantiate stores and handlers
6. Register 13 routes on `Router`, set `userId` from session
7. Dispatch, append `X-Request-Id` header to every response
8. Top-level `catch(\Throwable)` returns `500 internal_error` with optional trace when `XML2EMMET_DEBUG=1`

### Tests (`tests/`)

| Suite | Files | Count |
|-------|-------|-------|
| Engine unit tests (pre-existing) | `tests/*.php` | ~60 tests |
| DB store tests | `tests/Db/*` | 25 tests — require MySQL |
| HTTP unit tests | `tests/Http/Json\|Node\|Request\|Response\|Router\|ValidationTest.php` | 40 tests — no MySQL needed |
| HTTP integration tests | `tests/Http/Auth\|Transform\|Rules\|History\|Stats\|SmokeHttpTest.php` | 47 tests — require MySQL, each spawns `php -S` |
| **Total** | | **172 tests, 741 assertions** |

`HttpTestCase` base class: binds `php -S` to port 0 (OS-assigned free port), waits for it to accept connections, truncates all tables between tests, threads session cookies across requests within a test.

---

## What works (verified by running tests + manual curl)

- Register, login, logout, `/me` — session cookie (`xml2emmet_sid`, `HttpOnly`, `SameSite=Strict`) issued and cleared correctly
- Transform both directions (`xml2emmet`, `emmet2xml`), both modes (`xml`, `html`)
- `show_text`, `show_attrs`, `show_attr_values` toggles affect Emmet output (not the tree in the response)
- `rule_ids` — rules fetched, parsed, applied in order; foreign/unknown IDs return 404
- `click_ops` — all six operations (`swap`, `rename`, `wrap`, `unwrap`, `delete`, `move`) wired through HTTP; failures return the correct code + `op_index`
- `save: true` inserts history row, returns `saved_id`
- Rules CRUD with cross-user isolation (user A cannot read, update, or delete user B's rules)
- History pagination — newest first, correct `total`, `per_page` clamped 1–100
- Stats HTML branch — element count, tag count, attribute count, max depth, `top_classes`, `depth_histogram`
- Stats CSS branch — class count, `top_classes`
- Error envelopes on every response: `{error, message, details}` + `X-Request-Id`
- 413 rejected at front controller (before body is parsed) for `CONTENT_LENGTH > 2 MB`
- Migration script — idempotent, skips already-applied files, exits 0 on success

---

## What doesn't work / known issues

### Spec deviations (not crashes, but not quite right)

**`session_regenerate_id` on logout**
The spec says to call `session_regenerate_id(true)` on both login and logout. `Session::login()` does this correctly. `Session::logout()` calls `session_destroy()` instead — functionally equivalent (destroy is stronger than regenerate), but it's a literal deviation from the spec.
File: `src/Http/Session.php:25`

**Router 405 uses wrong error code**
When a path is known but the HTTP method is wrong, the router returns `405 Method Not Allowed` with `error: "not_found"` in the envelope. The status code and the error code disagree.
File: `src/Http/Router.php:42`

**`show_attrs: false` — tree still shows attrs**
The `show_attrs`/`show_attr_values` toggles are applied as a post-filter before the Emmet emitter, so the Emmet output string is correct. But the `tree` field in the response always reflects the unfiltered tree. A client reading `body.tree` and `body.output` will see different things.
File: `src/Http/Handlers/TransformHandler.php:88`

### Security / robustness gaps (not blocking for local dev, matters before production)

**Login timing leak**
When the username doesn't exist, `AuthHandler::login()` returns early without calling `password_verify()`. This leaks whether a username exists via response timing.
File: `src/Http/Handlers/AuthHandler.php:34`

**No cap on `click_ops` or `rule_ids` array length**
An authenticated user can send 100,000 click-ops or rule IDs. `RuleStore::findUnownedIds` builds a dynamic `IN (?,?,?...)` of arbitrary length. No limit is enforced.
Files: `src/Http/Handlers/TransformHandler.php:36`, `src/Db/RuleStore.php:57`

**`NodeJson` and `filterTree` are unboundedly recursive**
Deep nesting in input XML can overflow the PHP call stack. No depth limit is enforced.
Files: `src/Http/NodeJson.php:36`, `src/Http/Handlers/TransformHandler.php:117`

**`Json::encode` lacks `JSON_THROW_ON_ERROR`**
If a stored history row contains invalid UTF-8, `json_encode` returns `false` silently and the response body is empty with a 200 status.
File: `src/Http/Json.php:7`

**`secure` cookie flag defaults to off**
`XML2EMMET_SECURE_COOKIE` defaults to `0`. Deploying without explicitly setting it to `1` issues session cookies over plain HTTP.
File: `src/Config.php:30`

### Test gaps

- No test asserts `X-Request-Id` is present on responses
- No test asserts the session cookie is named `xml2emmet_sid`
- No HTTP test for click-op codes other than `bad_path` — `unknown_op`, `root_delete`, `unwrap_root`, `missing_with`, `missing_to` are tested at the engine level but not through HTTP
- No test for `show_text: false` or `show_attr_values: false` toggles
- No test exercises the `XML2EMMET_DEBUG=1` trace toggle

---

## What is explicitly out of scope for this branch

Per the spec (`docs/superpowers/specs/2026-05-25-http-api-design.md` §1):

- Browser SPA / frontend client — no HTML UI, only the JSON API
- CI configuration — no `.github/workflows`, no Dockerfile for deployment
- CSRF tokens, rate limiting, password reset
- Variadic rule placeholders, conditional rules, rule sharing

---

## Running tests

Requires MySQL (or the Docker container used during development):

```bash
# Start MySQL via Docker
docker run -d --name xml2emmet-mysql \
  -e MYSQL_ROOT_PASSWORD=secret \
  -e MYSQL_DATABASE=xml2emmet_test \
  -p 3307:3306 mysql:8.0

# Wait ~20s for MySQL to be ready, then apply schema
XML2EMMET_DB_HOST=127.0.0.1 XML2EMMET_DB_PORT=3307 \
XML2EMMET_DB_USER=root XML2EMMET_DB_PASS=secret \
XML2EMMET_DB_NAME=xml2emmet_test php bin/migrate.php

# Run tests
XML2EMMET_DB_HOST=127.0.0.1 XML2EMMET_DB_PORT=3307 \
XML2EMMET_DB_USER=root XML2EMMET_DB_PASS=secret \
XML2EMMET_DB_NAME=xml2emmet_test vendor/bin/phpunit
```

Engine unit tests and HTTP unit tests (no DB) run without any env vars:

```bash
vendor/bin/phpunit tests/Http/JsonTest.php tests/Http/NodeJsonTest.php \
  tests/Http/RequestTest.php tests/Http/ResponseTest.php \
  tests/Http/RouterTest.php tests/Http/ValidationTest.php
```

## Running the dev server

```bash
XML2EMMET_DB_HOST=127.0.0.1 XML2EMMET_DB_PORT=3307 \
XML2EMMET_DB_USER=root XML2EMMET_DB_PASS=secret \
XML2EMMET_DB_NAME=xml2emmet \
php -S 127.0.0.1:8080 -t public
```
