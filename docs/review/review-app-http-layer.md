# Review: App\Http Layer (Request, Response, Json, NodeJson, Router, Session, Validation) + public/index.php Bootstrap

**Verdict:** Acceptable — the layer is clean, testable, and safe for its current same-origin SPA context. Several fixes are 1–5 line changes that eliminate real bugs before they bite in production.

---

## Overview

A self-contained PHP HTTP layer for a JSON API. Seven classes handle the full request/response cycle: `Request` parses globals, `Json` wraps `json_encode`/`json_decode`, `Response` is an immutable value object, `Router` linearly matches regex routes with an auth gate, `Session` manages PHP sessions with cookie hardening, `Validation` validates a decoded JSON body field by field, and `NodeJson` serialises/deserialises the domain `Node` tree. The entry point `public/index.php` bootstraps all of the above and scatters a handful of cross-cutting concerns (request-id injection, 413 guard, 500 catch) directly in the bootstrap rather than in any middleware chain.

No middleware pipeline exists. CORS is intentionally absent because the frontend is served same-origin. CSRF surface is limited by design (all mutation endpoints require `Content-Type: application/json`, which browsers cannot send cross-site without a pre-flight, and no CORS response headers are sent to authorise pre-flight).

---

## What Exists

### Classes and responsibilities

| Class | File | Responsibility |
|---|---|---|
| `Request` | `src/Http/Request.php` | Parse `$_SERVER`/`$_GET`/`php://input` into an immutable value object |
| `Response` | `src/Http/Response.php` | Immutable status+headers+body; `send()` is the only side-effect |
| `Json` | `src/Http/Json.php` | Thin `json_encode`/`json_decode` wrappers |
| `NodeJson` | `src/Http/NodeJson.php` | Bidirectional `Node` ↔ plain array serialisation |
| `Router` | `src/Http/Router.php` | Linear regex route table with method check and auth gate |
| `Session` | `src/Http/Session.php` | PHP session start/login/logout/userId with hardened cookie params |
| `Validation` | `src/Http/Validation.php` | Field-level validation accumulator over a decoded JSON body |
| `index.php` | `public/index.php` | Bootstrap: session start, 413 guard, DI wiring, routing, error handler |

### Registered routes (index.php)

| Method | Path | Gated | Handler |
|---|---|---|---|
| POST | `/api/auth/register` | No | `AuthHandler::register` |
| POST | `/api/auth/login` | No | `AuthHandler::login` |
| POST | `/api/auth/logout` | Yes | `AuthHandler::logout` |
| GET | `/api/auth/me` | Yes | `AuthHandler::me` |
| POST | `/api/transform` | Yes | `TransformHandler::transform` |
| GET | `/api/rules` | Yes | `RulesHandler::list` |
| POST | `/api/rules` | Yes | `RulesHandler::create` |
| PUT | `/api/rules/{id}` | Yes | `RulesHandler::update` |
| DELETE | `/api/rules/{id}` | Yes | `RulesHandler::delete` |
| GET | `/api/history` | Yes | `HistoryHandler::list` |
| GET | `/api/history/{id}` | Yes | `HistoryHandler::detail` |
| POST | `/api/stats` | Yes | `StatsHandler::stats` |

---

## What's Good

**`Request::fromGlobals()` header normalisation** (`src/Http/Request.php:35–45`) correctly handles the PHP `HTTP_` namespace and explicitly captures `CONTENT_TYPE` and `CONTENT_LENGTH` — the two headers PHP omits from the `HTTP_` prefix loop. This is a well-known PHP gotcha and the code handles it precisely.

**Lazy JSON decode in the `Request` constructor** (`Request.php:21–25`). The constructor only calls `Json::decode` when `Content-Type` contains `application/json` and no pre-parsed array was supplied. This keeps the constructor deterministic and makes unit-testing with injected payloads straightforward.

**`Response` as a pure value object.** `send()` is the only method with observable side-effects. Every handler returns a `Response` that can be constructed and inspected in a test without running a server.

**RFC 7231-correct 404 vs 405** (`Router.php:28–45`). The `$matchedPath` sentinel is set to `true` after any path match, so a request with a matching path but wrong method returns 405, not 404. This is the behaviour RFC 7231 requires.

**Session hardening** (`Session.php:10–17`). `SameSite=Strict`, `HttpOnly`, configurable `secure` flag, and `session_regenerate_id(true)` on login are all present. The logout sequence zeros `$_SESSION`, expires the cookie, and destroys the session — the full three-step sequence.

**`Json::decode` contract** (`Json.php:9–12`). Swallowing `\JsonException` and returning `null` pairs correctly with the `is_array()` guard in `Request`'s constructor. A scalar JSON root (`"hello"`, `42`) is discarded rather than silently accepted as a body.

**`NodeJson::fromArray` structural validation** (`NodeJson.php:20–41`). Every field — tag, attrs (string→string), text (string|null), children (array) — is validated before constructing a `Node`. No silent coercions.

**413 guard reads `CONTENT_LENGTH` before body buffer** (`index.php:29–36`). Preventing the body from being read into memory at all for clearly oversized requests is the correct approach.

**`X-Request-Id` is injected on every response path** (`index.php:32`, `77`, `83`), including 413 early-exits and 500 catch blocks. Every response the client receives carries a correlation id.

**Integration test breadth.** `AuthHttpTest`, `RulesHttpTest`, `TransformHttpTest`, `HistoryHttpTest`, `StatsHttpTest`, and `SmokeHttpTest` all use a real `php -S` process and cover auth flow, session persistence, cross-user isolation, pagination, parse errors, and the 413 limit.

---

## What's Bad

### 🔴 `Json::encode` missing `JSON_THROW_ON_ERROR` — silent empty-body bug

`Json.php:7`:
```php
return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
```

`json_encode` returns `false` for non-UTF-8 strings, `NaN`, `INF`, resources, or circular references. `Response::json()` passes the return value directly to `new Response(...)` as `string $body`. PHP casts `false` to `''`. The client receives `HTTP 200` with an empty body and no diagnostic. Add `JSON_THROW_ON_ERROR` — the encode call becomes:
```php
return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```
The exception propagates to the `\Throwable` catch in `index.php`, which returns a proper 500. One flag prevents a silent data-loss failure mode.

### 🟡 `Response::send()` omits `Content-Length`

`Response.php:23–27`. The response body is always a fully-buffered string; `strlen($this->body)` is always available. Without `Content-Length`, HTTP keep-alive connection reuse is impossible and debugging truncated responses requires a packet capture. Add `header("Content-Length: " . strlen($this->body));` before `echo`.

### 🟡 `Session::logout()` loses `SameSite` on the expiry `Set-Cookie`

`Session.php:29`:
```php
setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
```
The 7-argument positional form of `setcookie()` has no parameter for `SameSite`. The expiry `Set-Cookie` header is therefore sent without `SameSite=Strict`, which is inconsistent with the original session cookie. Some strict browser implementations log a warning or refuse to match the cookie. Replace with the PHP 7.3+ options-array form:
```php
setcookie(session_name(), '', [
    'expires'  => time() - 42000,
    'path'     => $p['path'],
    'domain'   => $p['domain'] ?? '',
    'secure'   => $p['secure'],
    'httponly' => $p['httponly'],
    'samesite' => 'Strict',
]);
```

### 🟡 `Validation::requireMatch()` leaks internal regex to API consumers

`Validation.php:34`:
```php
$this->errors[$field] = "must match $regex";
```
An error like `must match /^[A-Za-z0-9_]{3,64}$/` exposes an implementation detail and is not useful to an end user. `requireMatch` should accept a `string $label` parameter and use that in the error: `"must be a valid $label"`. The calling code (`AuthHandler::register`) already knows the constraint is a username.

### 🟡 `Validation::requireString()` uses byte length, not character length

`Validation.php:14`:
```php
if (!is_string($v) || strlen($v) < $min || strlen($v) > $max)
```
`strlen()` counts bytes. A CJK character occupies 3 bytes in UTF-8; a rule name of 43 CJK characters (129 bytes) fails a `max=128` check even though MySQL `VARCHAR(128) CHARACTER SET utf8mb4` would accept it. For username this is harmless (the regex in `requireMatch` enforces ASCII). For rule names it silently truncates the usable range for non-ASCII input. Either switch to `mb_strlen($v, 'UTF-8')` or document explicitly that limits are byte counts.

### 🟡 `Config::secureCookie` defaults to `false`

`Config.php:32`:
```php
secureCookie: ($env['XML2EMMET_SECURE_COOKIE'] ?? '0') === '1',
```
A production deployment that forgets `XML2EMMET_SECURE_COOKIE=1` silently transmits session cookies over HTTP. Defaulting to `true` and requiring explicit opt-out for development is the safer posture. This is a deployment footgun rather than a code bug, but the default is wrong for a production web service.

### 🟢 `Content-Length` bypass in the 413 guard

`index.php:29`:
```php
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 2_000_000) { ... }
```
Two bypass vectors: (1) a client that omits `Content-Length` entirely causes `(int)null = 0`, skipping the guard entirely; (2) `php://input` reads until the connection closes regardless of the `Content-Length` value, so a client that lies with a small header sends an arbitrarily large body. The per-handler `requireString(max=2_000_000)` check protects against misuse after the fact, but by then the body is already in PHP memory. Add a secondary check after `file_get_contents`:
```php
if (strlen($raw) > 2_000_000) { /* 413 */ }
```

### 🟢 Router 405-before-401 leaks path existence to unauthenticated clients

`Router.php:28–45`. When an unauthenticated client sends `HEAD` or `OPTIONS` to a gated path (e.g. `/api/rules`), `$matchedPath` is set to `true`, the method loop exhausts without hitting the gate check (no HEAD/OPTIONS handler is registered), and the router returns 405. An unauthenticated client can therefore enumerate every registered API path. For this application the impact is low (the path list is not sensitive), but the fix is straightforward: check the gate before returning 405, or return 401 for any method on a gated path when the user is unauthenticated.

### 🟢 `error_log` includes full stack traces in non-debug mode

`index.php:85`:
```php
error_log("[$requestId] uncaught: " . $e->getMessage() . "\n" . $e->getTraceAsString());
```
The full trace is logged regardless of `$cfg->debug`. If `error_log` points to a web-accessible file (common on shared hosting), internal class names, file paths, and DB query strings are exposed. In production, consider logging trace to a non-web-accessible log destination or suppressing trace in the log when debug is off.

---

## Bugs & Risks

| Severity | ID | Location | Description |
|---|---|---|---|
| 🔴 | SILENT-EMPTY-BODY | `Json::encode` | Missing `JSON_THROW_ON_ERROR`; `json_encode` returns `false` on unencodable input; `(string)false = ''`; client gets HTTP 200 with empty body |
| 🟡 | LOGOUT-SAMESITE | `Session::logout()` line 29 | Positional `setcookie()` cannot set `SameSite`; expiry cookie sent without `SameSite=Strict` |
| 🟡 | TIMING-ATTACK | `AuthHandler::login()` line 35 | `($user === null \|\| !password_verify(...))` short-circuits; null-username path costs ~1 µs vs ~200 ms for hash verify; attacker can enumerate valid usernames by timing |
| 🟡 | SESSION-FIXATION-WINDOW | `Session::start()` | Session reused before identity known; closed by `session_regenerate_id(true)` in `login()`, but `session.use_strict_mode` is not set so unsolicited session IDs are accepted |
| 🟢 | CONTENT-LENGTH-BYPASS | `index.php` line 29 | Absent or lied-about `Content-Length` bypasses 2 MB guard; body is in memory before any secondary check |
| 🟢 | PATH-ENUMERATION | `Router::dispatch()` | Unauthenticated HEAD/OPTIONS on a gated path returns 405 (path exists) instead of 401/404 |
| 🟢 | NODEJSON-STACK-DEPTH | `NodeJson::fromArray()` | No recursion depth guard; `json_decode`'s default depth of 512 could produce 512-level PHP call stack if the method is ever wired to user input |

### Timing attack detail (new finding)

`AuthHandler::login()` line 35:
```php
if ($user === null || !password_verify($password, $user['password_hash'])) {
```
PHP `||` short-circuits. When `findByUsername` returns `null`, `password_verify` is never called. `bcrypt` verification takes 100–300 ms; the null path takes microseconds. A timing oracle lets an attacker reliably distinguish "username does not exist" from "wrong password".

Fix: always call `password_verify` against a pre-computed sentinel hash:
```php
$hash = $user['password_hash'] ?? '$2y$12$aaaaaaaaaaaaaaaaaaaaaa.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; // constant
if ($user === null || !password_verify($password, $hash)) {
    return $vague;
}
```

---

## Missing Features

| Priority | Feature | Impact if absent |
|---|---|---|
| 🟡 | CSRF token on `POST /api/auth/logout` | Attacker on same-site subdomain can force logout (disruptive, not data-loss) |
| 🟡 | Rate limiting on `/api/auth/login` and `/api/auth/register` | Brute-force and credential-stuffing are unrestricted |
| 🟢 | HEAD method support | RFC 7231 requires servers responding to GET to respond to HEAD; currently returns 405 |
| 🟢 | OPTIONS method support | Required for CORS pre-flight if the API is ever consumed cross-origin; currently returns 404 |
| 🟢 | `Content-Length` response header | Required for HTTP/1.1 keep-alive; currently absent from all responses |
| 🟢 | `optionalString` / `optionalInt` / `optionalArray` in `Validation` | `optionalBool` exists but the analogous helpers for other types do not; callers must use manual `isset` checks outside the validation context |
| 🟢 | Middleware / hook pipeline | Cross-cutting concerns (CORS, rate-limit, request-id) are scattered in `index.php`; a minimal `before` chain would make the bootstrap maintainable as route count grows |
| 🟢 | `session.use_strict_mode` configuration | Would reject unsolicited session IDs in `Session::start()`, closing the session fixation window without relying on application logic |

Note: CORS is intentionally absent for the same-origin SPA design. The "missing" label applies only in the event the API is consumed cross-origin (e.g. a mobile client or third-party integration).

---

## Improvement Ideas

Listed in approximate cost/benefit order:

1. **`Json::encode` — add `JSON_THROW_ON_ERROR`** (`Json.php:7`). Single flag, eliminates the silent-empty-body class of bugs entirely.

2. **`Session::logout()` — use options-array `setcookie()`** (`Session.php:29`). Five-line change, ensures `SameSite=Strict` is preserved on the expiry cookie.

3. **Timing-safe login** (`AuthHandler.php:35`). Add a sentinel `$2y$...` constant and always call `password_verify`; eliminates username enumeration via timing.

4. **Secondary `strlen($raw)` check in `index.php`** after `file_get_contents`. Closes the `Content-Length`-bypass vector. One if-statement after line 46 of `index.php`.

5. **`Content-Length` in `Response::send()`** (`Response.php:26`). One line: `header("Content-Length: " . strlen($this->body));`. Enables keep-alive, makes debugging easier.

6. **`Validation::requireMatch()` — add `$label` parameter** (`Validation.php:30–36`). Replace regex leak in error messages with a human-readable constraint description. Callers pass `'username'`; the error reads `'must be a valid username'`.

7. **`Validation::requireString()` — use `mb_strlen()`** (`Validation.php:14`). Or document that all limits are byte counts. For free-text fields (rule names), byte limits silently restrict multi-byte input inconsistently relative to the DB column definition.

8. **`Config::secureCookie` — default to `true`** (`Config.php:32`). Require explicit `XML2EMMET_SECURE_COOKIE=0` for local development rather than the other way around.

9. **HEAD method routing in `Router`** or in `index.php`. Either register a synthetic HEAD route for each GET route, or intercept HEAD in `dispatch()`: detect `$req->method === 'HEAD'`, re-run with method `'GET'`, and return the response with the body stripped. Removes the RFC 7231 non-compliance.

10. **Lightweight middleware array in `index.php`** — a `$before[]` list of `Closure(Request): ?Response` callables. Each middleware returns `null` to continue or a `Response` to short-circuit. CORS headers, rate-limit checks, and content-type enforcement belong here rather than inline in the bootstrap.

---

## Test Coverage

The unit test suite covers `Request`, `Response`, `Router`, `Json`, `Validation`, and `NodeJson` for their primary happy paths and most error paths. `RouterTest` covers static routes, parameterised capture, 404, 405, and both gate outcomes.

The integration suite (`AuthHttpTest`, `RulesHttpTest`, `TransformHttpTest`, `HistoryHttpTest`, `StatsHttpTest`, `SmokeHttpTest`) spins a real `php -S` process and verifies auth flow, session persistence across requests, cross-user data isolation, pagination, transform parse errors, and the 413 payload limit.

**Coverage gaps:**

| Gap | Risk |
|---|---|
| No test for `Json::encode` with unencodable input (`NaN`, non-UTF-8 string) | The silent-empty-body bug has no regression test |
| No test for `Content-Length` absent from the 413 guard | Bypass vector has no regression test |
| No test for `HEAD` or `OPTIONS` on any route | RFC 7231 non-compliance undetected |
| No test for `SameSite` attribute on the `Set-Cookie: ... expires` header from `Session::logout()` | Logout cookie inconsistency undetected |
| No test for `Validation::requireString()` with multi-byte input at the boundary | Byte-vs-character discrepancy undetected |
| No test for `Validation::requireMatch()` error message content | Regex leak in error messages undetected |
| No test for login timing (unit-level) | Timing attack findability undetected |
| `NodeJson::fromArray` is untested with deeply nested input | Stack-depth risk not exercised |

---

## Verdict

The HTTP layer is well-structured and demonstrates clear design judgement: immutable value objects, a minimal constructor contract, correct RFC status code handling, and solid session hardening. The integration test suite is genuinely broad for a project of this size. For the current deployment context — a same-origin SPA where CORS is intentionally absent — there are no open critical vulnerabilities.

The one confirmed critical issue is `Json::encode` missing `JSON_THROW_ON_ERROR`: any non-encodable value produces a silent 200 with an empty body, a failure mode that is invisible in normal operation but catastrophic when triggered. The timing oracle in `AuthHandler::login()` is the second priority fix; it is easy to exploit and easy to close. The logout `SameSite` gap and `secureCookie` default are small defensive improvements. All four can be fixed in under 30 lines of code changes combined.

**Rating: acceptable** — ready for production with the `JSON_THROW_ON_ERROR` fix applied; the timing attack and `secureCookie` default should follow before any significant user growth.
