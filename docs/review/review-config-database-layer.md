# Review: Config + Database Layer

## Overview

This review covers the config-and-database layer of a multi-user XML-to-Emmet transformation app: `Config.php`, `Db.php`, `UserStore.php`, `RuleStore.php`, `HistoryStore.php`, and `schema.sql`. The layer is PHP 8.x with a MySQL InnoDB backend accessed via PDO.

A verification pass was run against the full application source. Several findings from the initial analysis were overturned by examining call sites (marked as false positives below). One new bug was discovered: a TOCTOU silent-success in `RulesHandler.update`. The overall verdict is **needs-work**.

---

## What Exists

| File | Responsibility |
|---|---|
| `Config.php` | Readonly value object hydrated from environment variables |
| `Db.php` | Static factory returning a configured PDO connection |
| `UserStore.php` | CRUD for users; password hashing and verification |
| `RuleStore.php` | CRUD for transformation rules scoped by user |
| `HistoryStore.php` | Insert and paginated retrieval of transformation history |
| `schema.sql` | MySQL InnoDB DDL for `users`, `rules`, `transformations` tables |

### Schema at a glance

| Table | Key columns | Notable constraints |
|---|---|---|
| `users` | `id`, `username`, `password_hash`, `created_at` | UNIQUE KEY on `username` |
| `rules` | `id`, `user_id`, `name`, `pattern_emmet`, `replacement_emmet`, `updated_at` | FK `user_id` → `users(id)` ON DELETE CASCADE |
| `transformations` | `id`, `user_id`, `rule_ids`, `input`, `output`, `direction`, `settings`, `created_at` | FK `user_id` → `users(id)` ON DELETE CASCADE; ENUM on `direction`; MEDIUMTEXT for `input`/`output` |

---

## What's Good

**Type safety and immutability.** `Config` uses `declare(strict_types=1)` and PHP 8 `readonly` properties. Once constructed it cannot be mutated.

**PDO hardening.** `ERRMODE_EXCEPTION`, `FETCH_ASSOC`, and `EMULATE_PREPARES=false` are all set. Disabling emulated prepares is the most important single PDO hardening step — it ensures parameters are bound at the protocol level rather than interpolated in PHP, and it is done correctly.

**Unicode consistency.** `charset=utf8mb4` appears in both the DSN and the schema DDL, so emoji and supplementary Unicode characters are handled end-to-end without silent truncation.

**Prepared statements throughout.** Every DML in every store uses positional placeholders. There is no string interpolation of user-supplied data anywhere in the layer.

**Ownership enforcement in queries.** `RuleStore.findOwned` and `HistoryStore.findOwned` both scope by `user_id` before `id` in the WHERE clause. Ownership is verified by the database engine, not by a post-fetch application check.

**`findUnownedIds` is server-side.** The set-difference computation uses `array_fill` to build a parameterized `IN (?,?,?)` clause. No SQL injection vector.

**`LIMIT`/`OFFSET` binding.** `HistoryStore.listForUser` uses `bindValue` with explicit `PDO::PARAM_INT` for the pagination parameters — required when `EMULATE_PREPARES=false` on older MySQL drivers that reject string-typed LIMIT values.

**`JSON_THROW_ON_ERROR` everywhere.** No silent serialisation failures.

**DB-level uniqueness on username.** The UNIQUE KEY enforces uniqueness at the engine level, not solely in application logic.

**Referential integrity.** Foreign keys with `ON DELETE CASCADE` are declared. Deleting a user removes their rules and history without application-layer cleanup.

**Composite index for pagination.** `idx_tx_user_created (user_id, created_at DESC)` directly serves `HistoryStore.listForUser`'s `WHERE user_id = ? ORDER BY created_at DESC` query.

**`PASSWORD_DEFAULT` for hashing.** Automatically upgrades the algorithm as PHP's default changes, without requiring a schema migration.

**`direction` is a DB-level ENUM.** Invalid direction values are rejected by MySQL, not only by application validation.

---

## What's Bad

### Bugs (confirmed)

**🔴 Dangling reference after `foreach (&$r)` — latent maintenance hazard.**
Both `RuleStore.listForUser` and `HistoryStore.listForUser` use `foreach ($rows as &$r)` and never call `unset($r)` after the loop. In the current code each loop is immediately followed by `return $rows`, so the alias is never exercised and there is no observable impact today. However, if any code is inserted between the loop and the return, the last element of `$rows` will be silently overwritten. Fix: add `unset($r)` after every foreach-by-reference loop.

**🔴 `RulesHandler.update` TOCTOU with discarded return value — NEW BUG.**
`RulesHandler.update` calls `findOwned` (returns the rule or sends 404), then calls `RuleStore.update()` and **discards the return value** (line 33). If a concurrent request deletes the rule between those two calls, `update()` returns `false` but the handler still responds `200 {ok:true}`. This is a real silent-success bug introduced by the lack of transaction wrapping and the ignored return value. Fix: check `RuleStore.update()`'s return value and treat `false` as 404, or wrap `findOwned + update` in a transaction and rely on `rowCount`.

**🟡 `HistoryStore.listForUser` page=0 silently clamps to page 1.**
`max(0, ($page - 1) * $perPage)` when `$page=0` computes `max(0, -perPage) = 0`, which is the offset for page 1. The HTTP handler (`HistoryHandler.php` line 13) masks this with its own `max(1, ...)`, but any direct caller of the store receives page 1 instead of an error. The store must validate `$page >= 1` and throw `\InvalidArgumentException`.

**🟡 `RuleStore.findUnownedIds` cannot distinguish missing from foreign-owned.**
A rule ID that does not exist in the table at all produces the same result as one that exists but belongs to a different user — both appear in the returned array. `TransformHandler` responds HTTP 404 "Unknown rule id" for both cases, giving callers no way to distinguish a typo from an authorization failure. The method signature is also `array $ids` with no integer-type enforcement at the store boundary; non-integer values would be stringified by PDO rather than rejected.

### Design problems

**🟡 `HistoryStore.listForUser` COUNT and SELECT are not in a transaction.**
The total count and the page rows are fetched in two separate queries with no snapshot isolation. Under concurrent inserts the count can be stale relative to the rows, producing inconsistent pagination envelopes (e.g., `total=5` reported while 6 rows are visible across pages). Fix: wrap both queries in a `REPEATABLE READ` transaction.

**🟡 `UserStore.findByUsername` and `findById` always `SELECT password_hash`.**
`AuthHandler.me` calls `findById` on every authenticated request and then discards `password_hash`. The hash is transmitted from MySQL to PHP on every session refresh. Split into `findByUsernameForAuth` (returns hash) and `findByUsername` (returns `id`, `username`, `created_at` only).

**🟡 `transformations` stores full `MEDIUMTEXT` on every page fetch.**
`listForUser` calls `fetchAll` and loads both `input` and `output` columns (up to 16 MB each) for every row on the page (up to 100 rows). The 2 MB request-body guard at ingestion reduces individual entry sizes in practice, but older large entries still load in full on every paginated list. The list endpoint should return a preview (e.g., first 500 chars) and reserve full text for `findOwned`.

**🟡 No SSL/TLS options in `Db::connect()`.**
`PDO::MYSQL_ATTR_SSL_CA` and `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` are not set. On any non-localhost deployment, credentials and query results travel in plaintext. Severity depends on network topology but this is a deployment-level risk.

**🟡 No connection timeout in `Db::connect()`.**
`PDO::ATTR_TIMEOUT` / `PDO::MYSQL_ATTR_CONNECT_TIMEOUT` is absent. A blocked MySQL handshake holds the PHP-FPM worker for the full OS TCP timeout (up to 120 seconds), exhausting the worker pool under a connectivity fault.

**🟡 `DATETIME` columns with no timezone enforcement.**
All three tables use `DATETIME DEFAULT CURRENT_TIMESTAMP`. On a MySQL server whose session timezone is not UTC, `ORDER BY created_at DESC` silently produces wrong ordering across DST boundaries. `TIMESTAMP` columns (stored by MySQL as UTC internally) would be safer.

**🟢 `HistoryStore.insert` accepts `$direction` as a bare `string`.**
The DB ENUM and the HTTP handler's `requireEnum` call prevent invalid values from persisting in practice, but there is no type-system enforcement at the store boundary. A future caller bypassing the HTTP handler could pass an invalid direction and receive an opaque `SQLSTATE 23000` at runtime.

**🟢 `HistoryStore.listForUser` silently clamps `page=0`** (see bug section above — dual concern).

---

## Bugs & Risks

| Severity | Location | Description |
|---|---|---|
| 🔴 | `RulesHandler.update` | TOCTOU: `findOwned` + `update()` without transaction; return value discarded; concurrent delete produces `200 {ok:true}` silently |
| 🔴 | `RuleStore.listForUser`, `HistoryStore.listForUser` | Dangling `&$r` after foreach; latent overwrite hazard if code is inserted before `return` |
| 🟡 | `HistoryStore.listForUser` | `page=0` clamps silently to page 1; store contract violation masked by HTTP handler |
| 🟡 | `UserStore.create` | Duplicate username raises `PDOException` with `SQLSTATE 23000`; generic catch cannot distinguish from connection failure |
| 🟡 | `Db::connect()` | No SSL/TLS; credentials in plaintext on non-localhost deployments |
| 🟡 | `Db::connect()` | No connect timeout; worker process blocks up to 120 s under MySQL fault |
| 🟡 | `HistoryStore.listForUser` | COUNT + SELECT in separate queries; pagination envelope can be stale under concurrent inserts |
| 🟡 | `HistoryStore.insert` | `$settings` and `$ruleIds` stored as opaque JSON blobs; malformed structures persist silently, fail only on read |
| 🟡 | All tables | `DATETIME` columns; ORDER BY incorrect across DST on non-UTC MySQL servers |
| 🟢 | `RuleStore.findUnownedIds` | Cannot distinguish non-existent ID from foreign-owned ID; misleading 404 "Unknown rule id" |
| 🟢 | `UserStore.findByUsername` / `findById` | `password_hash` fetched on every call including non-auth paths |
| 🟢 | `schema.sql` | No `IF NOT EXISTS`; no migration versioning; re-applying DDL on existing DB fails |

---

## Missing Features

**Cursor-based pagination for `HistoryStore`.** Offset pagination (`LIMIT n OFFSET k`) requires MySQL to scan and discard all preceding rows. At high page numbers this degrades to a full table scan. A cursor on `(created_at, id)` served by the existing `idx_tx_user_created` composite index would be O(log n) regardless of page number.

**Soft deletes.** No `deleted_at` column exists on any table. Accidental deletion of a rule or transformation record is unrecoverable. Adding a `deleted_at DATETIME NULL` column and filtering `WHERE deleted_at IS NULL` on all queries would provide a safety net at minimal schema cost.

**User account management fields.** There is no `email`, no password-reset token, no email-verification flag, no `last_login_at`, and no account-disabled flag. A user who loses their password has no recovery path.

**Per-user quotas at the data layer.** There is no row-count cap on `transformations` or `rules` per user. A single user can fill both tables without any enforcement.

**Optimistic locking on rules.** Concurrent edits from two browser sessions silently last-write-wins. A `version INT` column used as a precondition in `UPDATE ... WHERE version = ?` would prevent silent overwrites.

**Audit log for rule changes.** `rules.updated_at` records the timestamp of the last change but the previous values of `pattern_emmet` and `replacement_emmet` are permanently discarded on every update.

**Database migration tooling.** The schema is a single-file `CREATE TABLE` dump with no versioning, no up/down migrations, and no `IF NOT EXISTS` guards. `bin/migrate.php` exists but the schema file itself has no re-apply safety.

**Config validation for port range and session name format.** Port is not validated to be in 1–65535. Session name is not validated to be alphanumeric-only, which is required for safe cookie name construction.

---

## Improvement Ideas

1. **`Direction` backed enum.** Introduce `enum Direction: string { case Xml2Emmet = 'xml2emmet'; case Emmet2Xml = 'emmet2xml'; }` and use it as the type of `$direction` in `HistoryStore.insert`. Validation moves from the HTTP handler into the type system.

2. **Split `UserStore.findByUsername`.** Create `findByUsernameForAuth(string $username): ?array` (returns hash) and `findByUsername(string $username): ?array` (returns `id`, `username`, `created_at` only). The hash never leaves the authentication code path.

3. **`Config::validate()` called inside `fromEnv`.** Assert non-empty host, name, and session name (the current defaults are acceptable, but explicit validation surfaces intent). Validate port in 1–65535 and session name matching `[a-zA-Z0-9_]`. Throw `\InvalidArgumentException` with a per-field message rather than letting a bad value cause an opaque PDO error later.

4. **`$maxPerPage` constant and clamping.** Add `const MAX_PER_PAGE = 100` to both list stores and apply `$perPage = min($perPage, self::MAX_PER_PAGE)`. The HTTP handler clamps already, but the store must protect itself.

5. **Wrap COUNT + SELECT in a transaction in `HistoryStore.listForUser`.** Use `REPEATABLE READ` to ensure the total count and the page rows come from the same snapshot.

6. **Return text previews from `listForUser`.** Select only the first 500 characters of `input` and `output` (e.g., `LEFT(input, 500)`) in the paginated list query. Full text is returned only from `findOwned`.

7. **Add SSL and timeout options to `Db::connect()`.** Pass `PDO::MYSQL_ATTR_CONNECT_TIMEOUT => 5` and optionally `PDO::MYSQL_ATTR_SSL_CA => $caPath` in the options array.

8. **Fix TOCTOU in `RulesHandler.update`.** Either check `RuleStore.update()`'s return value and map `false` to 404, or wrap `findOwned + update` in a single transaction and use `rowCount` as the authoritative signal.

9. **`unset($r)` after every foreach-by-reference loop.** Eliminates the dangling reference in `RuleStore.listForUser` and `HistoryStore.listForUser`.

10. **Add `UNIQUE KEY uniq_rules_user_name (user_id, name)` to the `rules` table** if rule names are intended to be unique per user. Currently two rules with identical names for the same user are silently accepted.

11. **Extract a `ConnectionFactory`.** Replace the static `Db::connect()` with a factory or DI-container binding that returns a shared PDO instance for the duration of a request. This eliminates the risk of accidentally calling `connect()` multiple times and makes the connection lifecycle explicit.

---

## Test Coverage

No tests are visible in the provided code. Coverage is assumed zero.

The static `Db::connect()` method makes unit testing require either a real MySQL connection or a mocking shim. The stores accept a raw `PDO` in their constructors — a SQLite in-memory PDO or a PHPUnit mock can be injected — which is a good foundation.

**Critical paths with no test coverage:**

| Priority | Path | Why it matters |
|---|---|---|
| 1 | `Config::fromEnv` with missing or empty required vars | Validates startup-fail behavior |
| 2 | `UserStore.create` with a duplicate username | Verifies SQLSTATE 23000 propagation vs. connection error |
| 3 | `RulesHandler.update` with concurrent delete | Confirms TOCTOU fix actually closes the silent-success window |
| 4 | `RuleStore.findUnownedIds` with mixed owned, unowned, and nonexistent IDs | Confirms correct categorization of each case |
| 5 | `HistoryStore.listForUser` with `page=0` and `page=1` boundary | Validates the InvalidArgumentException on page=0 |
| 6 | `HistoryStore.listForUser` under concurrent inserts | Confirms count/page consistency after transaction fix |
| 7 | `HistoryStore.insert` with malformed JSON in `$settings` | Confirms fail-fast behavior or graceful degradation |

---

## Verdict

The foundation is well-constructed: prepared statements throughout, correct PDO hardening, ownership checks pushed into SQL, DB-level constraints on `direction` and `username` uniqueness, and `readonly` config properties. These are not accidental — they reflect deliberate, informed choices.

However, several issues make the layer unsuitable for production without fixes. The most urgent is the TOCTOU silent-success in `RulesHandler.update` (discarded return value + no transaction), which silently acknowledges a write that did not happen. The COUNT/SELECT pagination race, missing SSL/timeout options in `Db::connect()`, the absence of any transaction wrapping across multi-step operations, and the dangling `&$r` reference (latent, not live) are moderate concerns. The missing soft-delete, audit log, and per-user quota enforcement are feature gaps that will become pain points as usage grows. The schema migration story (no `IF NOT EXISTS`, no versioning) must be resolved before the first deployment.

**Rating: needs-work**
