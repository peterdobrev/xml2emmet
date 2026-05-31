# Review: HTTP Handlers + Front Controller

**Component:** `AuthHandler`, `TransformHandler`, `RulesHandler`, `HistoryHandler`, `StatsHandler`, `public/index.php`, `Router`, `Session`, `Request`, `Response`, `Validation`
**Verdict:** Acceptable — well-structured with clear design intent; three confirmed bugs need fixing before production load.

---

## Overview

A plain-PHP JSON API layer over a tree-transformation engine. Five handlers cover auth (session-cookie), transform (parse → rules → click-ops → filter → emit), rules CRUD, paginated history, and stateless stats. The front controller in `public/index.php` wires them through a regex router with an auth gate, a 2 MB body guard, a global `Throwable` catch, and per-request ID logging. All DB access is parameterised PDO with `ATTR_EMULATE_PREPARES => false`.

The design is deliberate and self-consistent. The spec explicitly defers rate limiting, CSRF tokens, and Unicode passwords — those omissions are not reviewed as defects here.

---

## What Exists

### Endpoints

| Method | Path | Auth | Handler method |
|--------|------|------|---------------|
| POST | `/api/auth/register` | open | `AuthHandler::register` |
| POST | `/api/auth/login` | open | `AuthHandler::login` |
| POST | `/api/auth/logout` | gated | `AuthHandler::logout` |
| GET | `/api/auth/me` | gated | `AuthHandler::me` |
| POST | `/api/transform` | gated | `TransformHandler::transform` |
| GET | `/api/rules` | gated | `RulesHandler::list` |
| POST | `/api/rules` | gated | `RulesHandler::create` |
| PUT | `/api/rules/{id}` | gated | `RulesHandler::update` |
| DELETE | `/api/rules/{id}` | gated | `RulesHandler::delete` |
| GET | `/api/history` | gated | `HistoryHandler::list` |
| GET | `/api/history/{id}` | gated | `HistoryHandler::detail` |
| POST | `/api/stats` | gated | `StatsHandler::stats` |

### Transform pipeline (5 steps)

1. Parse input via `TransformEngine::xmlParse` or `emmetParse`
2. Validate + apply user rules (pattern/replacement Emmet trees)
3. Apply click-ops in order
4. `filterTree` — strips attrs/text per settings flags
5. Emit output + optionally save to history

### Supporting classes

- `Request` — PHP 8 readonly value object; lazily decodes JSON body in constructor
- `Response` — readonly value object; `json()` and `error()` static factories
- `Validation` — accumulates field errors; used in every handler
- `Session` — thin static wrapper around PHP sessions
- `Router` — regex-based with `matchedPath` flag to distinguish 404 from 405
- `Json` — encode/decode helpers; `decode` swallows `JsonException`
- `RuleStore`, `HistoryStore`, `UserStore` — parameterised PDO wrappers

---

## What's Good

**Value objects and immutability.** `Request`, `Response`, and `Node` are PHP 8 `readonly` value objects. The immutable tree makes the five-step transform pipeline safe to reason about — each step returns a new `Node` rather than mutating shared state.

**Authentication correctness.** `password_verify` is timing-safe. The 401 message is intentionally vague (`'Username or password is incorrect.'`), preventing username enumeration. `session_regenerate_id(true)` on login prevents session fixation. Cookie params — `httponly`, `samesite=Strict`, configurable `secure` — are all correct.

**Complete logout.** `Session::logout` clears `$_SESSION`, expires the cookie via `setcookie`, and calls `session_destroy`. All three steps are necessary and present.

**Body guard ordering.** The `CONTENT_LENGTH` check in `index.php:29–36` fires before `Request::fromGlobals()` at line 38, so `php://input` is never read for oversized normal requests. The 413 response includes the `X-Request-Id` header even on this early return path.

**Request tracing.** `$requestId = bin2hex(random_bytes(8))` is generated at the top of every request. It appears on every response (`X-Request-Id` header), in the access log via `log_line()`, and in the `Throwable` catch block. Elapsed milliseconds are included. This is production-ready observability for a project of this size.

**Router 404 vs 405 distinction.** `Router::dispatch` tracks `$matchedPath` to return 405 when the path matches but the method does not, rather than a generic 404.

**Bulk ownership check before N individual queries.** `RuleStore::findUnownedIds` uses a single `IN(...)` query to detect foreign rule IDs before the rule-loading loop. This prevents a foreign-ID request from issuing per-rule queries before being rejected.

**`PARAM_INT` on LIMIT/OFFSET.** `HistoryStore::listForUser` uses `PDO::PARAM_INT` on the `LIMIT` and `OFFSET` bind values, working around PHP PDO's string-cast behaviour for integer bindings.

**Emmet validation on rule save.** `RulesHandler::validateBody` parses both `pattern` and `replacement` through `TransformEngine::emmetParse` before storing them. Only valid Emmet is ever persisted, preventing stored rules from causing unexpected parse failures at transform time.

**`ATTR_EMULATE_PREPARES => false`.** `Db::connect` disables prepared-statement emulation, so the MySQL driver sends real prepared statements at the protocol level.

**Test harness port allocation.** `HttpTestCase::setUpBeforeClass` binds a socket to port 0, reads the OS-assigned port, then launches `php -S` on that port. This avoids port conflicts when running test classes in parallel.

---

## What's Bad

### HTTP status codes for resource creation

`AuthHandler::register` (line 24) and `RulesHandler::create` (line 23) both return `200 OK` on successful creation. HTTP semantics call for `201 Created` with a `Location` header. This is a spec-compliance choice, not a runtime bug, but it is a missed convention that will confuse HTTP-level tooling (caches, monitors, API gateways).

### N+1 query in `TransformHandler` rule loading

`findUnownedIds` (one `IN(...)` query) confirms all IDs are owned. Then the `foreach` loop at `TransformHandler.php:63` calls `findOwned($userId, $rid)` once per rule ID — a separate `SELECT ... WHERE user_id = ? AND id = ?` per call. For a request with 50 rule IDs this is 51 queries. The fix is a single `SELECT ... WHERE id IN (...) AND user_id = ?` keyed by ID, after which `findUnownedIds` becomes redundant.

### `ClickOpError::$code` accessed via `__get`

`ClickOpError.php` stores the error code as `private readonly string $errorCode` and aliases it through `__get('code')`. The access at `TransformHandler.php:84` (`$e->code`) is invisible to static analysis — PHPStan at level 8+ reports it as an access to an unknown property. A `public readonly string $code` property eliminates the magic and works correctly with IDEs and type-checkers.

### `Session::logout` magic constant `42000`

`Session.php:29` uses `time() - 42000` (approximately 11.67 hours) to expire the session cookie. Any sufficiently negative offset works at runtime, but the magic number is unexplained. The conventional value is `1` or `time() - 3600`. Future maintainers will waste time decoding this.

### Error merge with PHP array union operator

`TransformHandler.php:41` uses `$v->errors() + $sv->errors()` to merge top-level and settings validation errors. The union operator silently drops keys from the right operand when they duplicate keys on the left. The field sets do not currently overlap, but this is a maintenance trap. `array_merge` preserves all errors (with numeric key re-indexing) or the settings errors could be nested under a `'settings'` key.

### `RuleStore::listForUser` returns all rows

`RuleStore.php:17–25` issues `SELECT ... WHERE user_id = ? ORDER BY ...` with no `LIMIT`. A user with thousands of rules gets all rows in a single response. The history endpoint has pagination; the rules endpoint does not. No spec requirement exists for this, but the inconsistency and latent memory pressure are worth noting.

### `HistoryStore::listForUser` non-atomic COUNT + SELECT

`HistoryStore.php:33–53` issues a `COUNT(*)` then a `SELECT ... LIMIT ? OFFSET ?` as two separate queries without a transaction or snapshot isolation. Under concurrent writes the `total` may not match the returned page. This is a cosmetic inconsistency, not a data-integrity issue, but it can surface in load tests.

### All handler objects constructed on every request

`index.php:40–48` constructs all five handlers, four stores, and a PDO connection unconditionally on every request, including those that return early (e.g. 413, 404, 401). For the current route set this is acceptable. As a pattern it scales poorly: future static routes (health checks) will still pay for a MySQL handshake.

### `Json::encode` missing `JSON_THROW_ON_ERROR`

`Json.php:7` does not pass `JSON_THROW_ON_ERROR`. If `json_encode` encounters an unencodable value it returns `false`. With `declare(strict_types=1)` and a `string` return type, PHP throws a `TypeError` rather than silently producing the string `'false'` — so the actual failure mode is a 500 via the global `Throwable` handler, not silent data corruption. The claimed severity is lower than the analysis report suggests. Adding `JSON_THROW_ON_ERROR` is still the right call for explicit error handling intent. Note: `HistoryStore` and `Request` already use `JSON_THROW_ON_ERROR` correctly.

### Log format deviates from spec

`log_line()` emits `[$rid] $status {$ms}ms $msg`. The spec describes `[request_id] method path status duration_ms message`. Method and path are concatenated into the `$msg` argument at the call site (`index.php:79`) rather than being separate structured fields, and their order relative to status differs. The 413 early-return log at line 34 does not include method or path at all.

---

## Bugs & Risks

### 🔴 SECURITY — Chunked transfer bypass of the 2 MB body guard

**File:** `public/index.php:29`

```php
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 2_000_000) { ... }
```

`$_SERVER['CONTENT_LENGTH']` is absent for HTTP requests using `Transfer-Encoding: chunked`. The cast of `null ?? 0` yields `0`, so the guard never fires. `Request::fromGlobals()` then calls `file_get_contents('php://input')` unconditionally, buffering the entire body regardless of size. An authenticated user can stream an arbitrarily large body.

**Mitigation:** After `Request::fromGlobals()`, check `strlen($req->rawBody) > 2_000_000` and return 413. Alternatively, read `php://input` with `stream_copy_to_stream` and a `maxlength` argument.

### 🔴 CORRECTNESS — N+1 query in `TransformHandler` rule loading

**File:** `src/Http/Handlers/TransformHandler.php:57–73`

`findUnownedIds` (one query) is followed by `findOwned` per rule ID (one query per ID) inside the `foreach`. With 50 rule IDs: 51 queries. Additionally, a TOCTOU window exists: if a rule is deleted between `findUnownedIds` and the `findOwned` call for that ID, `findOwned` returns `null` and the dereference `$row['pattern_emmet']` on line 66 throws a `TypeError`, which the global handler converts to a 500 instead of a 404.

**Fix:** Replace both calls with `SELECT id, name, pattern_emmet, replacement_emmet FROM rules WHERE id IN (?) AND user_id = ?` keyed by ID. Eliminates the N+1 and closes the TOCTOU.

### 🔴 RELIABILITY — Stack overflow in recursive tree walkers

**Files:** `src/Stats.php:33–62` (`Stats::walk`), `src/Http/Handlers/TransformHandler.php:118–133` (`filterTree`), `src/Http/NodeJson.php` (`toArray`), `src/XmlParser.php` (`parseElement`)

All four are recursive with no depth guard. A document like `<a><a><a>...</a></a></a>` at roughly 7 bytes per level can exceed 280,000 levels within the 2 MB body limit. PHP's default stack handles approximately 40,000–80,000 frames. The 2 MB body limit does not adequately constrain nesting depth.

**Fix:** Add a `$maxDepth` parameter (default 200) to `XmlParser` and throw `XmlParseError` if exceeded. The recursive walkers are then safe because they can only receive trees the parser accepted.

### 🟡 CORRECTNESS — TOCTOU race in duplicate-username registration

**File:** `src/Http/Handlers/AuthHandler.php:19–23`

`findByUsername` checks for uniqueness, then `UserStore::create` inserts. Two concurrent registrations with the same username both pass the `null` check; one INSERT succeeds and the other hits the `UNIQUE` constraint, triggering a `PDOException` that propagates to the global handler and returns 500 instead of 409. The database constraint prevents corruption, but the client receives the wrong status code.

### 🟡 RELIABILITY — `ClickOpError::$code` invisible to static analysis

**File:** `src/ClickOpError.php:8–13`, accessed at `src/Http/Handlers/TransformHandler.php:84`

PHPStan at level 8+ flags `$e->code` as an unknown property access. The `__get` bridge is not needed.

### 🟢 LATENT — `RuleStore::listForUser` unbounded result set

**File:** `src/Db/RuleStore.php:17–25`

No `LIMIT` clause. A user with a very large rule set receives an unbounded payload with no way to paginate.

---

## Missing Features

| Feature | Notes |
|---------|-------|
| Rate limiting on `register` and `login` | Spec-deferred; brute-force unmitigated at app layer |
| CSRF protection beyond `SameSite=Strict` | Spec-acknowledged; subdomain attacks unmitigated |
| `DELETE /api/history/{id}` | Schema supports single-row deletion; handler and route are absent |
| Rule enable/disable toggle | Users must delete to disable |
| `GET /api/transform/{id}/restore` | Mentioned in spec schema notes as a design goal; no API surface |
| `GET /api/health` | Required for load-balancer liveness probes |
| `DELETE /api/users/me` | No account-deletion flow |
| Password change endpoint | Even an authenticated current+new change is absent |
| Pagination on `GET /api/rules` | History is paginated; rules are not |

---

## Improvement Ideas

**1. Eliminate the N+1 rule query.** Replace `findUnownedIds` + per-ID `findOwned` with a single `SELECT ... WHERE id IN (...) AND user_id = ?`:

```php
$rows = $this->rules->findByIds($userId, $ruleIds); // returns array keyed by id
$missing = array_diff(array_map('intval', $ruleIds), array_keys($rows));
if ($missing !== []) {
    return Response::error(404, 'not_found', 'Unknown rule id.', ['rule_ids' => $missing]);
}
```

This also closes the TOCTOU window and halves the query count.

**2. Add max-depth to `XmlParser`.** A `$maxDepth = 200` parameter passed through `parseElement` prevents pathologically nested documents from reaching any recursive walker. One guard point protects `Stats::walk`, `filterTree`, `NodeJson::toArray`, and the rules engine simultaneously.

**3. Close the chunked-transfer bypass.** After reading the body, check actual length:

```php
$req = Request::fromGlobals();
if (strlen($req->rawBody) > 2_000_000) {
    // return 413
}
```

**4. Replace `ClickOpError::__get` with a public property.** `public readonly string $code` in place of the private `$errorCode` + `__get` bridge. One line change; eliminates a PHPStan false positive category.

**5. Add `JSON_THROW_ON_ERROR` to `Json::encode`.** Aligns with `HistoryStore` and `Request`, makes error handling explicit, and avoids a TypeError reaching the global `Throwable` handler.

**6. Paginate `GET /api/rules`.** Accept `page`/`per_page` query params in `RulesHandler::list`, consistent with the history endpoint pattern already implemented in `HistoryHandler` and `HistoryStore`.

**7. Return 201 for resource creation.** `register` and `POST /api/rules` should return `201 Created` with a `Location` header. The test harness uses `assertSame(200, ...)` and will need updating.

**8. Lazy PDO connection.** Pass a factory closure or a connection pool wrapper to handlers rather than connecting at request startup in `index.php`. This avoids a MySQL handshake for routes that do not touch the database (e.g. health check, 413 early return).

**9. Add `GET /api/health`.** No auth, no DB, returns `{"ok":true}`. Required for any load balancer or container readiness probe.

**10. Structured log fields.** Restructure `log_line` to accept `method` and `path` as separate parameters emitted as fixed positional fields, matching the spec format `[rid] method path status ms message`.

**11. Add `DELETE /api/history/{id}`.** `HistoryStore` already supports single-row deletion via the `findOwned` + PDO delete pattern established in `RuleStore::delete`. The handler method and route are one-liners each.

**12. Handle the duplicate-username race.** Wrap `findByUsername` + `create` in a try-catch for `PDOException` with `SQLSTATE 23000`, and return 409 instead of 500.

---

## Test Coverage

**Strengths:**

- The HTTP test suite exercises all twelve endpoints against a real `php -S` server and a live test database.
- Happy paths are covered for every endpoint.
- Error paths tested include: validation failures, duplicate username, wrong password, anonymous access to gated routes, parse errors, bad click-op paths, foreign rule IDs, and `per_page` clamping.
- Cross-user isolation is tested at the HTTP level for both rules (`RulesHttpTest::testCrossUserIsolation`) and history (`HistoryHttpTest::testDetailFetchAndCrossUser404`). This is the most critical security property and it is explicitly verified.
- `SmokeHttpTest::testFullJourney` covers the complete register → create rule → transform + save → list history → detail → logout flow. This catches wiring bugs that per-endpoint tests miss.
- The test harness port-zero allocation prevents flaky CI failures from port conflicts.

**Gaps:**

| Gap | Risk |
|-----|------|
| No test for chunked-transfer bypass of the 2 MB guard | Security bug undetected |
| No test for deeply nested document triggering stack overflow | Crash undetected |
| No test for 405 Method Not Allowed from `Router` | Router branch untested |
| No test verifying `X-Request-Id` header is present on every response | Tracing contract untested |
| `TransformHttpTest` covers only `rename` and `delete` click-ops | Four of six click-op types untested |
| `show_attr_values=false` toggle not tested | Filter logic gap |
| `emmet2xml` direction with rules applied not tested | Direction/rules interaction untested |
| Concurrent duplicate-username registration race not tested | TOCTOU produces 500 instead of 409 |
| N+1 query behaviour not tested | Performance regression undetectable |

---

## Verdict

The implementation is **acceptable**. The architecture is clean: readonly value objects, a well-ordered middleware stack, consistent validation patterns, and correctly implemented auth primitives. The parameterised query discipline is complete with no SQL injection surface. Three issues need to be resolved before the service handles production load: the chunked-transfer body guard bypass (a security hole for authenticated users), the N+1 rule query combined with its TOCTOU null-dereference (a correctness bug and a source of misleading 500 errors), and the unbounded recursion in all four tree walkers (a crash waiting for a sufficiently nested document). All three have straightforward fixes described above. The remainder — HTTP 201 status codes, the `__get` indirection, the log format gap, the missing health endpoint — are polish items that do not block deployment but should be addressed in the next iteration.
